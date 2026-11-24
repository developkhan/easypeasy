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

foreach ($sourceChannels as $c) {
    if (in_array(strtolower(ltrim($c, '@')), array_map('strtolower', $targetChannels), true)) {
        echo "::warning::یک کانال هم در مبدأ و هم در مقصد ثبت شده است: بررسی کن (کپی از یک کانال به خودش).\n";
    }
}

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
$linkMode = 'skip';
// پستی که منشن (@نام_کاربری) دارد کپی نشود
$skipMentions = true;
// ساخت خودکار هشتگ از کلمات مهم پست
$addHashtags = true;
$maxHashtags = 4;
// آیدی‌های اضافه‌ای که تبلیغ حساب نمی‌شوند (حروف کوچک، بدون @).
// منشن این‌ها از متن حذف می‌شود ولی پست کپی می‌شود. اگر نمی‌خواهی، مقدار را [] کن.
$extraAllowedHandles = ['akhbartelfori'];
// فوتر پیش‌فرض: اگر Secret به نام FOOTER_TEXT خالی بود، این متن زیر پست‌ها می‌آید
$defaultFooter = '@telkhabarfori';

if (trim($footerText) === '') {
    $footerText = $defaultFooter;
}

// آیدی کانال‌های خودمان (مبدأ و مقصد) + آیدی‌های مجاز اضافه: تبلیغ حساب نمی‌شوند
$allowedHandles = [];
foreach (array_merge($sourceChannels, $targetChannels, $extraAllowedHandles) as $c) {
    $allowedHandles[strtolower(ltrim($c, '@'))] = true;
}

define('LINK_MARK', "\u{E000}");

// بارگذاری پیام‌های ارسال‌شده قبلی
$sentMessages = [];
if (file_exists($sentFile)) {
    $sentMessages = json_decode(file_get_contents($sentFile), true) ?: [];
}

// نمایش خطا در صفحه خلاصه اجرای GitHub Actions (بدون قرمزکردن کل اجرا)
function ghError($msg) {
    echo "::error::" . str_replace(["\r", "\n"], ' ', $msg) . "\n";
}

// پیام اطلاع‌رسانی (آبی) در صفحه خلاصه اجرا
function ghNotice($msg) {
    echo "::notice::" . str_replace(["\r", "\n"], ' ', $msg) . "\n";
}

// ثبت دلیل نادیده‌گرفتن یک پست، هم در لاگ و هم در خلاصه اجرا
function logSkip($msg) {
    error_log($msg);
    ghNotice($msg);
}

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
            if (isOwnChannelUrl($href)) {
                $out .= LINK_MARK;
                continue;
            }
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

// تولید هشتگ از کلمات مهم متن (روش آماری ساده، بدون هوش مصنوعی)
function generateHashtags($text, $max) {
    if ($max <= 0) {
        return [];
    }
    $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // هشتگ‌های موجود در خود پست
    $existing = [];
    if (preg_match_all('/#([\p{L}\p{N}_]+)/u', $plain, $m)) {
        foreach ($m[1] as $h) {
            $existing[mb_strtolower($h)] = true;
        }
    }
    $plain = preg_replace('/#[\p{L}\p{N}_]+/u', ' ', $plain);
    $plain = str_replace(['ي', 'ك'], ['ی', 'ک'], $plain);

    $stopList = 'از به در با که را این آن برای تا یا هم های ها است بود شد شده شدند می نمی کرد کرده کردند کنند کند دارد دارند '
        . 'بر بین پس اما ولی اگر هر همه بسیار خیلی نیز حتی تنها فقط بعد قبل روی زیر چه چند چرا کجا کی من ما شما او آنها آنان '
        . 'ایشان خود باید شود شوند خواهد گفت گفته افزود اعلام گزارش امروز دیروز فردا سال روز ماه یک دو سه همچنین البته طبق طی '
        . 'علیه درباره توسط همان همین چنین دیگر دیگری بیش کمتر بیشتر پیش بدون مانند مثل وی آنکه اینکه چون زیرا بلکه نیست نبود '
        . 'هستند هست بوده بودند داشت داشته دادند داد داده میان طریق سوی جمله ضمن حال حالی عنوان کنید کنم کنیم بنابراین '
        . 'the and for with that this from are was were has have will your you not but';
    $stop = array_flip(explode(' ', $stopList));

    $lines = preg_split('/\n+/u', trim($plain));
    $title = $lines[0] ?? '';

    $tokenize = function ($str) use ($stop, $existing) {
        $out = [];
        if (preg_match_all('/[\p{L}\x{200C}]+/u', $str, $mm)) {
            foreach ($mm[0] as $w) {
                $w = preg_replace('/^\x{200C}+|\x{200C}+$/u', '', $w);
                $len = mb_strlen($w);
                if ($len < 3 || $len > 25) {
                    continue;
                }
                $lw = mb_strtolower($w);
                if (isset($stop[$lw]) || isset($existing[$lw])) {
                    continue;
                }
                // فعل‌های «می‌...» و «نمی‌...»
                if (mb_substr($w, 0, 3) === "می\u{200C}" || mb_substr($w, 0, 4) === "نمی\u{200C}") {
                    continue;
                }
                $out[] = $w;
            }
        }
        return $out;
    };

    $words = $tokenize($plain);
    if (count($words) < 5) {
        return [];
    }
    $inTitle = array_flip($tokenize($title));

    $score = [];
    $first = [];
    foreach ($words as $i => $w) {
        $score[$w] = ($score[$w] ?? 0) + 2;
        if (!isset($first[$w])) {
            $first[$w] = $i;
        }
    }
    foreach ($score as $w => $sc) {
        $score[$w] = $sc + (isset($inTitle[$w]) ? 3 : 0) + min(mb_strlen($w), 10) / 10;
    }
    uksort($score, function ($a, $b) use ($score, $first) {
        if ($score[$a] == $score[$b]) {
            return $first[$a] <=> $first[$b];
        }
        return $score[$b] <=> $score[$a];
    });

    $tags = [];
    foreach (array_keys($score) as $w) {
        $tags[] = '#' . str_replace("\u{200C}", '_', $w);
        if (count($tags) >= $max) {
            break;
        }
    }
    return $tags;
}

// آیا این آدرس به یکی از کانال‌های خودمان (مبدأ یا مقصد) اشاره دارد؟
function isOwnChannelUrl($url) {
    global $allowedHandles;
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~^(?:https?://)?(?:t\.me|telegram\.me)/(?:s/)?@?([A-Za-z0-9_]+)~i', $url, $m)) {
        return isset($allowedHandles[strtolower($m[1])]);
    }
    return false;
}

// خط‌هایی که نشانه (منشن/لینک کانال خودمان) دارند: اگر چیز مهمی در آن‌ها نمانده حذف می‌شوند
function cleanLinkMarks($text) {
    if (strpos($text, LINK_MARK) === false) {
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
            continue; // خط عملاً فقط امضا یا لینک بوده
        }
        $result[] = rtrim($rest);
    }
    return implode("\n", $result);
}

// اعمال قانون لینک‌ها روی متن
function applyLinkRules($text, &$hasLink) {
    global $linkMode, $allowedHandles;

    // منشن کانال‌های خودمان: حذف می‌شود (تبلیغ حساب نمی‌شود)
    $text = preg_replace_callback('/@([A-Za-z0-9_]{3,})/', function ($m) use ($allowedHandles) {
        return isset($allowedHandles[strtolower($m[1])]) ? LINK_MARK : $m[0];
    }, $text);

    if ($linkMode !== 'keep') {
        $urlPattern = '#(?:https?://|www\.|t\.me/)[^\s<]+#iu';
        $text = preg_replace_callback($urlPattern, function ($m) use (&$hasLink, $linkMode) {
            if (isOwnChannelUrl($m[0])) {
                return LINK_MARK;
            }
            if ($linkMode === 'skip') {
                $hasLink = true;
                return $m[0];
            }
            return LINK_MARK;
        }, $text);

        // آدرس بدون http (مثل a1cable.com) هم در حالت skip لینک حساب می‌شود
        if ($linkMode === 'skip') {
            $domainPattern = '#(?<![\p{L}\p{N}@._-])[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.(?:com|net|org|ir|info|biz|co|me|io|xyz|ly|tv|ru|app|site|online|shop)(?![\p{L}\p{N}])#iu';
            if (preg_match($domainPattern, strip_tags($text))) {
                $hasLink = true;
            }
        }
    }
    return cleanLinkMarks($text);
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
    global $skipForwarded, $linkMode, $skipMentions, $allowedHandles;
    if ($skipForwarded) {
        $fw = $xp->query(".//*[contains(@class,'tgme_widget_message_forwarded_from')]", $wrap);
        if ($fw && $fw->length > 0) {
            logSkip("[$channelId] پست فوروارد شده است و نادیده گرفته شد.");
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
        logSkip("[$channelId] پست لینک دارد و نادیده گرفته شد.");
        return null;
    }
    if ($skipMentions && preg_match_all('/@([A-Za-z0-9_]{3,})/', strip_tags($finalText), $mentionMatches)) {
        foreach ($mentionMatches[1] as $handle) {
            if (!isset($allowedHandles[strtolower($handle)])) {
                logSkip("[$channelId] پست منشن یک آیدی دیگر (@$handle) دارد و نادیده گرفته شد.");
                return null;
            }
        }
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
    $text = mb_scrub(cleanText($text), 'UTF-8');
    $footerText = mb_scrub($footerText, 'UTF-8');

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
        ghError('Telegram API error: ' . $response);
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
        logSkip("[$source] پست بدون متن و رسانه، نادیده گرفته شد.");
        continue;
    }

    $key = $post['postId'] !== '' ? $post['postId'] : $post['datetime'];
    $messageId = md5($post['channelId'] . '|' . $key);
    if (in_array($messageId, $sentMessages)) {
        continue;
    }

    // هشتگ‌ها (قبل از فوتر قرار می‌گیرند)
    $footerForPost = $footerText;
    if ($addHashtags) {
        try {
            $tags = generateHashtags($post['text'], $maxHashtags);
        } catch (Throwable $e) {
            error_log('خطا در تولید هشتگ: ' . $e->getMessage());
            $tags = [];
        }
        $tags = array_values(array_filter($tags, function ($t) {
            return mb_check_encoding($t, 'UTF-8');
        }));
        if (!empty($tags)) {
            $tagLine = implode(' ', $tags);
            $footerForPost = ($footerText !== '') ? $tagLine . "\n" . $footerText : $tagLine;
        }
    }

    // جایگزینی تصویر
    $photoToSend = $post['photo'];
    $videoToSend = $post['video'];
    $localImage = getReplacementImage($replacementImageName);
    if ($localImage) {
        $replaceList = array_map('strtolower', $replacePhotoChannels);
        $footerLen = !empty($footerForPost) ? mb_strlen($footerForPost) + 2 : 0;
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
        $sent = sendToTelegram($botToken, $target, $post['text'], $photoToSend, $videoToSend, $footerForPost);
        if (!$sent) {
            error_log("خطا در ارسال پیام به کانال مقصد: @$target");
            ghError("ارسال به کانال مقصد ناموفق بود: @$target");
        } else {
            $anySent = true;
        }
    }

    if ($anySent) {
        $sentMessages[] = $messageId;
        $updated = true;
        ghNotice("[$source] پست جدید کپی و ارسال شد.");
    }
}

if ($updated) {
    $sentMessages = array_slice($sentMessages, -500);
    file_put_contents($sentFile, json_encode($sentMessages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
