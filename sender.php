<?php

$configFile = __DIR__ . '/config.json';
$sentFile = __DIR__ . '/sent_messages.json';

// بارگذاری تنظیمات
if (!file_exists($configFile)) {
    die("فایل تنظیمات یافت نشد.");
}
$config = json_decode(file_get_contents($configFile), true);
if (!$config) {
    die("خطا در بارگذاری فایل تنظیمات.");
}

$sourceChannels = $config['source_channels'] ?? [];
$targetChannels = $config['target_channels'] ?? [];
$botToken = $config['bot_token'] ?? '';
$footerText = $config['footer_text'] ?? '';
$sourceChannels = array_values(array_unique($sourceChannels));
$targetChannels = array_values(array_unique($targetChannels));

// ===== تنظیمات =====
// نام فایل تصویر جایگزین (در ریپو، کنار sender.php)
$replacementImageName = 'banner.png';
// در این کانال‌های مبدأ، عکس پست با تصویر بالا عوض می‌شود (بدون @). خالی = هیچ‌کدام
$replacePhotoChannels = [];
// پست‌های متنی بدون عکس و ویدیو هم تصویر بالا را بگیرند
$addImageToTextPosts = true;
// پست‌های فوروارد شده در مبدأ کپی نشوند
$skipForwarded = true;
// رفتار با لینک‌ها (لینک سایت در متن یا پایین پست):
//   'remove' = لینک و خط‌هایی که فقط لینک بوده‌اند حذف شوند، بقیه پست کپی شود
//   'skip'   = پستی که لینک دارد اصلاً کپی نشود
//   'keep'   = لینک‌ها دست‌نخورده بمانند
$linkMode = 'remove';

define('LINK_MARK', "\u{E000}");

// پیدا کردن فایل تصویر جایگزین در ریپو
function getReplacementImage($name) {
    if ($name !== '') {
        $path = __DIR__ . '/' . $name;
        return file_exists($path) ? $path : null;
    }
    $files = glob(__DIR__ . '/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}', GLOB_BRACE);
    if (!$files) {
        return null;
    }
    foreach ($files as $f) {
        if (stripos(basename($f), 'favicon') === false) {
            return $f;
        }
    }
    return null;
}

// پاکسازی متن (حذف آیدی‌ها و فاصله‌های اضافی)
function cleanText($text) {
    $text = preg_replace('/<a[^>]*>(.*?)<\/a>/is', '$1', $text);
    $text = preg_replace('/@\w+/u', '', $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/^\s+|\s+$/m', '', $text);
    $text = preg_replace('/[\r\n]{2,}/', "\n", $text);
    return trim($text);
}

function fixUrl($url) {
    if ($url) {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = trim($url, "'\" ");
    }
    if ($url && !preg_match('/^https?:\/\//i', $url)) {
        $url = 'https:' . $url;
    }
    return $url;
}

// تبدیل محتوای پست به متن HTML امن برای تلگرام
// ایموجی‌های پرمیوم حذف می‌شوند و ایموجی‌های معمولی می‌مانند
function nodeToText($node, &$hasLink = false) {
    global $linkMode;
    $out = '';
    $allowed = ['b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del', 'code', 'pre'];
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $out .= htmlspecialchars($child->nodeValue, ENT_NOQUOTES, 'UTF-8');
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        $name = strtolower($child->nodeName);
        $class = strtolower($child->getAttribute('class'));

        if ($name === 'br') {
            $out .= "\n";
            continue;
        }

        // ایموجی پرمیوم (سفارشی): حذف کامل
        if ($name === 'tg-emoji'
            || $child->hasAttribute('emoji-id')
            || $child->hasAttribute('data-custom-emoji-id')
            || strpos($class, 'custom') !== false) {
            continue;
        }

        // لینک‌ها
        if ($name === 'a') {
            $href = $child->getAttribute('href');
            $plain = trim($child->textContent);
            $isTagOrMention = ($href === '' || $href[0] === '?' || $href[0] === '#' || mb_substr($plain, 0, 1) === '@');
            if ($isTagOrMention || $linkMode === 'keep') {
                $out .= nodeToText($child, $hasLink);
            } elseif ($linkMode === 'skip') {
                $hasLink = true;
                $out .= nodeToText($child, $hasLink);
            } else {
                $out .= LINK_MARK;
            }
            continue;
        }

        // ایموجی معمولی: فقط خود کاراکتر ایموجی
        if ($name === 'i' && strpos($class, 'emoji') !== false) {
            $out .= htmlspecialchars($child->textContent, ENT_NOQUOTES, 'UTF-8');
            continue;
        }

        $inner = nodeToText($child, $hasLink);
        if (in_array($name, $allowed, true)) {
            if (trim($inner) !== '') {
                $out .= "<$name>" . $inner . "</$name>";
            }
        } else {
            $out .= $inner;
        }
    }
    return $out;
}

// اعمال قانون لینک‌ها روی متن
function applyLinkRules($text, &$hasLink) {
    global $linkMode;
    if ($linkMode === 'keep') {
        return $text;
    }
    $urlPattern = '#(?:https?://|www\.|t\.me/)[^\s<]+#iu';
    if (preg_match($urlPattern, $text)) {
        if ($linkMode === 'skip') {
            $hasLink = true;
        } else {
            $text = preg_replace($urlPattern, LINK_MARK, $text);
        }
    }
    if ($linkMode !== 'remove') {
        return $text;
    }
    $result = [];
    foreach (explode("\n", $text) as $line) {
        if (strpos($line, LINK_MARK) === false) {
            $result[] = $line;
            continue;
        }
        $rest = str_replace(LINK_MARK, '', $line);
        $letters = preg_replace('/[^\p{L}\p{N}]+/u', '', strip_tags($rest));
        if (mb_strlen($letters) <= 30) {
            continue; // خط عملاً فقط لینک (یا دعوت به لینک) بوده
        }
        $result[] = rtrim($rest);
    }
    return implode("\n", $result);
}

function getLastTelegramPost($channelId) {
    $url = "https://t.me/s/$channelId";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) {
        error_log("دریافت صفحه کانال $channelId ناموفق بود.");
        return null;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    $wraps = $xp->query("//div[contains(@class,'tgme_widget_message_wrap')]");
    if ($wraps === false || $wraps->length === 0) {
        error_log("هیچ پستی در صفحه کانال $channelId پیدا نشد (کانال باید عمومی باشد).");
        return null;
    }
    $wrap = $wraps->item($wraps->length - 1);

    // پست فوروارد شده: کپی نمی‌شود
    global $skipForwarded, $linkMode;
    if ($skipForwarded) {
        $fw = $xp->query(".//*[contains(@class,'tgme_widget_message_forwarded_from')]", $wrap);
        if ($fw && $fw->length > 0) {
            error_log("[$channelId] پست فوروارد شده است و نادیده گرفته شد.");
            return null;
        }
    }

    // شناسه پست
    $postId = '';
    $msgNode = $xp->query(".//div[@data-post]", $wrap)->item(0);
    if ($msgNode) {
        $postId = $msgNode->getAttribute('data-post');
    }

    // زمان پست
    $datetime = '';
    $timeNode = $xp->query(".//time[@datetime]", $wrap)->item(0);
    if ($timeNode) {
        $datetime = $timeNode->getAttribute('datetime');
    }

    // متن
    $finalText = '';
    $textNode = $xp->query(".//div[contains(@class,'js-message_text')]", $wrap)->item(0);
    $hasLink = false;
    if ($textNode) {
        $finalText = nodeToText($textNode, $hasLink);
        $finalText = applyLinkRules($finalText, $hasLink);
    }
    if ($linkMode === 'skip' && $hasLink) {
        error_log("[$channelId] پست لینک دارد و نادیده گرفته شد.");
        return null;
    }

    // عکس: فقط از تگ عکس خود پست
    $photoUrl = null;
    $photoNode = $xp->query(".//a[contains(@class,'tgme_widget_message_photo_wrap')]", $wrap)->item(0);
    if ($photoNode) {
        $style = $photoNode->getAttribute('style');
        if (preg_match('/background-image:\s*url\(([^)]+)\)/i', $style, $pm)) {
            $photoUrl = fixUrl($pm[1]);
        }
    }
    if ($photoUrl && stripos($photoUrl, 'img/emoji') !== false) {
        $photoUrl = null;
    }

    // ویدیو
    $videoUrl = null;
    $videoNode = $xp->query(".//video[@src]", $wrap)->item(0);
    if ($videoNode) {
        $videoUrl = fixUrl($videoNode->getAttribute('src'));
    }

    error_log("[$channelId] پست: $postId | طول متن: " . mb_strlen($finalText)
        . " | عکس: " . ($photoUrl ? 'دارد' : 'ندارد')
        . " | ویدیو: " . ($videoUrl ? 'دارد' : 'ندارد'));

    return [
        'text' => $finalText,
        'datetime' => $datetime,
        'postId' => $postId,
        'channelId' => $channelId,
        'photo' => $photoUrl,
        'video' => $videoUrl,
    ];
}

// دانلود فایل روی سرور و ارسال به‌صورت آپلود (مطمئن‌تر از ارسال با لینک)
function downloadFile($url) {
    $tmp = tempnam(sys_get_temp_dir(), 'tg');
    $fp = fopen($tmp, 'wb');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code >= 400 || filesize($tmp) < 100) {
        error_log("دانلود فایل ناموفق بود (کد $code).");
        @unlink($tmp);
        return null;
    }
    return $tmp;
}

// کوتاه‌کردن متن HTML بدون خراب‌شدن تگ‌ها
function truncateHtml($html, $max) {
    if (mb_strlen($html) <= $max) {
        return $html;
    }
    $cut = mb_substr($html, 0, $max - 1);

    // برش از آخرین فاصله یا خط جدید
    $p1 = mb_strrpos($cut, "\n");
    $p2 = mb_strrpos($cut, ' ');
    $pos = max((int)$p1, (int)$p2);
    if ($pos > $max * 0.6) {
        $cut = mb_substr($cut, 0, $pos);
    }

    // حذف تگ یا موجودیت نیمه‌کاره در انتها
    $cut = preg_replace('/<[^>]*$/u', '', $cut);
    $cut = preg_replace('/&[a-z#0-9]*$/iu', '', $cut);
    $cut = rtrim($cut);

    // بستن تگ‌های باز
    $stack = [];
    if (preg_match_all('#<(/?)(b|strong|i|em|u|s|strike|del|code|pre)>#i', $cut, $m, PREG_SET_ORDER)) {
        foreach ($m as $t) {
            $tag = strtolower($t[2]);
            if ($t[1] === '') {
                $stack[] = $tag;
            } elseif (!empty($stack) && end($stack) === $tag) {
                array_pop($stack);
            }
        }
    }
    while (!empty($stack)) {
        $cut .= '</' . array_pop($stack) . '>';
    }
    return $cut . '…';
}

function sendToTelegram($botToken, $chatId, $text, $photo = null, $video = null, $footerText = '') {
    $full = cleanText($text);
    $clean = $full;
    $hasMedia = !empty($photo) || !empty($video);

    // کپشن عکس/ویدیو حداکثر ۱۰۲۴ کاراکتر است؛ متن بلندتر کوتاه می‌شود
    if ($hasMedia) {
        $footerLen = !empty($footerText) ? mb_strlen($footerText) + 2 : 0;
        $limit = 1000 - $footerLen;
        if (mb_strlen($clean) > $limit) {
            $clean = truncateHtml($clean, $limit);
        }
    }

    $ok = sendOnce($botToken, $chatId, $clean, $photo, $video, $footerText);
    if (!$ok && $hasMedia) {
        error_log('ارسال رسانه ناموفق بود، ارسال به‌صورت متن...');
        $ok = sendOnce($botToken, $chatId, $full, null, null, $footerText);
    }
    return $ok;
}

function sendOnce($botToken, $chatId, $text, $photo = null, $video = null, $footerText = '') {
    $text = cleanText($text);

    if (!empty($footerText)) {
        $footer = htmlspecialchars($footerText, ENT_NOQUOTES, 'UTF-8');
        $text = ($text !== '') ? $text . "\n\n" . $footer : $footer;
    }

    $tmpFile = null;

    if ($video || $photo) {
        $isVideo = !empty($video);
        $src = $isVideo ? $video : $photo;
        $method = $isVideo ? 'sendVideo' : 'sendPhoto';
        $field = $isVideo ? 'video' : 'photo';

        $isLocal = !preg_match('#^https?://#i', $src) && file_exists($src);
        $tmpFile = $isLocal ? null : downloadFile($src);
        $filePath = $isLocal ? $src : $tmpFile;
        $data = [
            'chat_id' => "@$chatId",
            'caption' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($filePath) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimes = ['png' => 'image/png', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];
            $mime = $isVideo ? 'video/mp4' : ($mimes[$ext] ?? 'image/jpeg');
            $fname = $isLocal ? basename($filePath) : ($isVideo ? 'video.mp4' : 'photo.jpg');
            $data[$field] = new CURLFile($filePath, $mime, $fname);
        } else {
            $data[$field] = $src;
        }
        if ($isVideo) {
            $data['supports_streaming'] = 'true';
        }
    } else {
        $method = 'sendMessage';
        $data = [
            'chat_id' => "@$chatId",
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
    }

    $url = "https://api.telegram.org/bot$botToken/$method";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $response = curl_exec($ch);

    if ($response === false) {
        error_log('Curl error: ' . curl_error($ch));
    }
    curl_close($ch);

    if ($tmpFile) {
        @unlink($tmpFile);
    }

    $res = json_decode($response, true);
    if (!$res || empty($res['ok'])) {
        error_log('Telegram API error: ' . $response);
    }
    return $res && isset($res['ok']) && $res['ok'] === true;
}

// حلقه بررسی و ارسال پست‌ها
$updated = false;

foreach ($sourceChannels as $source) {
    $post = getLastTelegramPost($source);
    if (!$post) {
        continue;
    }

    if ($post['text'] === '' && empty($post['photo']) && empty($post['video'])) {
        error_log("[$source] پست بدون متن و رسانه، نادیده گرفته شد.");
        continue;
    }

    $key = $post['postId'] !== '' ? $post['postId'] : $post['datetime'];
    $messageId = md5($post['channelId'] . '|' . $key);
    if (in_array($messageId, $sentMessages)) {
        continue;
    }

    // جایگزینی تصویر
    $photoToSend = $post['photo'];
    $videoToSend = $post['video'];
    $localImage = getReplacementImage($replacementImageName);
    if ($localImage) {
        $replaceList = array_map('strtolower', $replacePhotoChannels);
        $footerLen = !empty($footerText) ? mb_strlen($footerText) + 2 : 0;
        if ($photoToSend && !$videoToSend && in_array(strtolower($source), $replaceList, true)) {
            $photoToSend = $localImage;
            error_log("[$source] عکس پست با تصویر جایگزین عوض شد.");
        } elseif ($addImageToTextPosts && !$photoToSend && !$videoToSend
            && mb_strlen(cleanText($post['text'])) + $footerLen <= 1000) {
            $photoToSend = $localImage;
            error_log("[$source] تصویر جایگزین به پست متنی اضافه شد.");
        }
    } elseif (!empty($replacePhotoChannels) || $addImageToTextPosts) {
        error_log('فایل تصویر جایگزین در ریپو پیدا نشد.');
    }

    $anySent = false;
    foreach ($targetChannels as $target) {
        $sent = sendToTelegram($botToken, $target, $post['text'], $photoToSend, $videoToSend, $footerText);
        if (!$sent) {
            error_log("خطا در ارسال پیام به کانال مقصد: @$target");
        } else {
            $anySent = true;
        }
    }

    if ($anySent) {
        $sentMessages[] = $messageId;
        $updated = true;
    }
}

if ($updated) {
    file_put_contents($sentFile, json_encode($sentMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
