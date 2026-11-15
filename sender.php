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
$targetChannels = $config['target_channels'] ?? []; // تغییر این خط: حالا آرایه
$botToken = $config['bot_token'] ?? '';
$footerText = $config['footer_text'] ?? '';

if (!$botToken || empty($targetChannels) || empty($sourceChannels)) {
    die("تنظیمات ناقص است.");
}

// بارگذاری پیام‌های ارسال‌شده قبلی
$sentMessages = [];
if (file_exists($sentFile)) {
    $sentMessages = json_decode(file_get_contents($sentFile), true) ?: [];
}

// تابع پاکسازی متن (حذف لینک‌ها و آیدی‌ها)
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

function getLastTelegramPost($channelId) {
    $url = "https://t.me/s/$channelId";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return null;

    preg_match_all('/<div class="tgme_widget_message_wrap js-widget_message_wrap".*?<\/div>\s*<\/div>/s', $html, $messages);
    if (empty($messages[0])) return null;

    $last = end($messages[0]);

    preg_match('/<div class="tgme_widget_message_text js-message_text"[^>]*>(.*?)<\/div>/s', $last, $textMatch);
    $rawHtml = $textMatch[1] ?? '';

    $rawHtml = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $rawHtml);

    // حذف ایموجی‌های پرمیوم (سفارشی)
    $rawHtml = preg_replace('#<tg-emoji\b[^>]*>.*?</tg-emoji>#is', '', $rawHtml);
    $rawHtml = preg_replace('#<i\b[^>]*custom[^>]*>.*?</i>#is', '', $rawHtml);
    // ایموجی‌های معمولی: <i class="emoji"><b>😀</b></i> فقط خود ایموجی بماند
    $rawHtml = preg_replace('#<i\b[^>]*class="emoji[^"]*"[^>]*>\s*<b>(.*?)</b>\s*</i>#is', '$1', $rawHtml);

    $allowedTags = '<b><strong><i><em><u><s><strike><del><a><code><pre>';
    $cleanHtml = strip_tags($rawHtml, $allowedTags);
    // حذف ویژگی‌های اضافی تگ‌ها و تگ‌های خالی
    $cleanHtml = preg_replace('#<(b|strong|i|em|u|s|strike|del|code|pre)\b[^>]*>#i', '<$1>', $cleanHtml);
    $cleanHtml = preg_replace('#<(b|strong|i|em|u|s|strike|del|code|pre)>\s*</\1>#i', '', $cleanHtml);
    $finalText = html_entity_decode($cleanHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // آدرس عکس فقط از داخل خود تگ عکس پست خوانده می‌شود (نه از ایموجی‌ها)
    $photoUrl = null;
    if (preg_match('/<a\b[^>]*tgme_widget_message_photo_wrap[^>]*>/s', $last, $aTag)) {
        if (preg_match('/background-image:\s*url\(([^)]+)\)/i', $aTag[0], $pm)) {
            $photoUrl = $pm[1];
        }
    }
    if ($photoUrl && stripos($photoUrl, 'img/emoji') !== false) {
        $photoUrl = null;
    }

    preg_match('/<video[^>]+src="([^"]+)"/', $last, $videoMatch);
    $videoUrl = $videoMatch[1] ?? null;

    $photoUrl = fixUrl($photoUrl);
    $videoUrl = fixUrl($videoUrl);

    preg_match('/<time datetime="([^"]+)"[^>]*>([^<]+)<\/time>/', $last, $timeMatch);
    $datetime = $timeMatch[1] ?? null;

    return [
        'text' => $finalText,
        'datetime' => $datetime,
        'channelId' => $channelId,
        'photo' => $photoUrl,
        'video' => $videoUrl,
    ];
}

function sendToTelegram($botToken, $chatId, $text, $photo = null, $video = null, $footerText = '') {
    $clean = cleanText($text);
    $hasMedia = !empty($photo) || !empty($video);

    // کپشن عکس/ویدیو حداکثر ۱۰۲۴ کاراکتر است
    if ($hasMedia && mb_strlen($clean . "\n\n" . $footerText) > 1000) {
        $ok1 = sendOnce($botToken, $chatId, '', $photo, $video, '');
        $ok2 = sendOnce($botToken, $chatId, $clean, null, null, $footerText);
        if ($ok1 || $ok2) return true;
        return false;
    }

    $ok = sendOnce($botToken, $chatId, $clean, $photo, $video, $footerText);
    if (!$ok && $hasMedia) {
        // اگر ارسال با عکس/ویدیو شکست خورد، فقط متن را بفرست
        error_log('ارسال رسانه ناموفق بود، ارسال به‌صورت متن...');
        $ok = sendOnce($botToken, $chatId, $clean, null, null, $footerText);
    }
    return $ok;
}

function sendOnce($botToken, $chatId, $text, $photo = null, $video = null, $footerText = '') {
    $text = cleanText($text);

    if (!empty($footerText)) {
        $footer = htmlspecialchars($footerText, ENT_NOQUOTES, 'UTF-8');
        $text = ($text !== '') ? $text . "\n\n" . $footer : $footer;
    }

    if ($video) {
        $url = "https://api.telegram.org/bot$botToken/sendVideo";
        $data = [
            'chat_id' => "@$chatId",
            'video' => $video,
            'caption' => $text,
            'parse_mode' => 'HTML',
        ];
    } elseif ($photo) {
        $url = "https://api.telegram.org/bot$botToken/sendPhoto";
        $data = [
            'chat_id' => "@$chatId",
            'photo' => $photo,
            'caption' => $text,
            'parse_mode' => 'HTML',
        ];
    } else {
        $url = "https://api.telegram.org/bot$botToken/sendMessage";
        $data = [
            'chat_id' => "@$chatId",
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    if ($response === false) {
        error_log('Curl error: ' . curl_error($ch));
    }
    curl_close($ch);

    $res = json_decode($response, true);
    if (!$res || !$res['ok']) {
        error_log('Telegram API error: ' . $response);
    }
    return $res && isset($res['ok']) && $res['ok'] === true;
}

// حلقه بررسی و ارسال پیام‌ها
$updated = false;

foreach ($sourceChannels as $source) {
    $post = getLastTelegramPost($source);
    if (!$post) {
        continue;
    }

    $messageId = md5($post['channelId'] . '|' . $post['datetime']);
    if (in_array($messageId, $sentMessages)) {
        continue;
    }

    // ارسال به همه کانال‌های مقصد
    $anySent = false;
    foreach ($targetChannels as $target) {
        $sent = sendToTelegram($botToken, $target, $post['text'], $post['photo'], $post['video'], $footerText);
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


