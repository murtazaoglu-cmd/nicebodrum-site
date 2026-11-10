<?php
/**
 * Nice Bodrum - Merkezi Form Gönderim Scripti (SMTP versiyonu - detaylı hata kaydı)
 * Hosting firmanız PHP'nin mail() fonksiyonunu kapattığı için,
 * bu sürüm kendi e-posta hesabınız üzerinden kimlik doğrulamalı (SMTP) gönderim yapar.
 *
 * ============================================================
 * AŞAĞIDAKİ SATIRLARI KONTROL EDİN (host/port/kullanıcı zaten dolu):
 * ============================================================
 */
define("SMTP_HOST", "mail.nicebodrum.com");
define("SMTP_PORT", 465);
define("SMTP_ENCRYPTION", "ssl");
define("SMTP_USER", "site@nicebodrum.com");
define("SMTP_PASS", "BURAYA_SIFRENIZI_YAZIN"); // SUNUCUDAKİ dosyada gerçek şifre yazılı; bu depo kopyasını üzerine yüklemeyin

// ============================================================
// Aşağısına dokunmanıza gerek yok.
// ============================================================

$alici_eposta = "murtazaoglu@gmail.com";
$site_adi     = "Nice Bodrum";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /");
    exit;
}

// ---- Spam koruması (honeypot) ----
if (!empty($_POST["website"])) {
    $donus = !empty($_POST["_donus"]) ? $_POST["_donus"] : "/";
    header("Location: " . $donus . (strpos($donus, "?") !== false ? "&" : "?") . "gonderildi=1");
    exit;
}

$form_turu = !empty($_POST["_konu"]) ? $_POST["_konu"] : "Web Sitesi Formu";

function temizle($deger) {
    $deger = trim($deger);
    $deger = str_replace(["\r", "\n"], " ", $deger);
    return $deger;
}

// ---- E-posta gövdesi ----
$govde = "$site_adi web sitesi uzerinden yeni bir form gonderimi:\r\n\r\n";
$govde .= str_repeat("-", 40) . "\r\n\r\n";
foreach ($_POST as $anahtar => $deger) {
    if (strpos($anahtar, "_") === 0 || $anahtar === "website") continue;
    if (is_array($deger)) $deger = implode(", ", $deger);
    $govde .= temizle($anahtar) . ": " . temizle($deger) . "\r\n";
}
$govde .= "\r\n" . str_repeat("-", 40) . "\r\n";
$govde .= "Gonderim zamani: " . date("d.m.Y H:i") . "\r\n";
$govde .= "Form turu: " . temizle($form_turu) . "\r\n";

$konu = "[$site_adi] " . temizle($form_turu);

// Reply-To her zaman kendi alan adımızdan olmalı (spam puanını düşürmek için).
// Ziyaretçinin e-postası zaten mesaj gövdesinde (form alanları arasında) görünüyor.
$yanit_adresi = SMTP_USER;

// ---- Log yardımcı fonksiyonu ----
function debug_kaydet($mesaj) {
    $satir = date("Y-m-d H:i:s") . " | " . $mesaj . "\n";
    @file_put_contents(__DIR__ . "/form_debug.log", $satir, FILE_APPEND);
}

// ---- Basit, kütüphanesiz SMTP istemcisi (detaylı loglu) ----
function smtp_oku($sock) {
    $veri = "";
    while ($satir = fgets($sock, 515)) {
        $veri .= $satir;
        if (isset($satir[3]) && $satir[3] === " ") break;
    }
    return $veri;
}

function smtp_gonder($sock, $komut, $etiket = "") {
    fwrite($sock, $komut . "\r\n");
    $yanit = smtp_oku($sock);
    if ($etiket) debug_kaydet("ADIM [$etiket] -> " . trim($yanit));
    return $yanit;
}

function form_maili_gonder($alici, $konu, $govde, $yanit_adresi) {
    $baglanti_adresi = (SMTP_ENCRYPTION === "ssl" ? "ssl://" : "tcp://") . SMTP_HOST . ":" . SMTP_PORT;
    debug_kaydet("Baglanmaya calisiliyor: $baglanti_adresi");

    $sock = @stream_socket_client($baglanti_adresi, $errno, $errstr, 15);
    if (!$sock) {
        debug_kaydet("BAGLANTI HATASI: errno=$errno errstr=$errstr");
        return false;
    }
    debug_kaydet("Baglanti kuruldu.");

    $karsilama = smtp_oku($sock);
    debug_kaydet("Karsilama: " . trim($karsilama));

    smtp_gonder($sock, "EHLO " . SMTP_HOST, "EHLO");

    if (SMTP_ENCRYPTION === "tls") {
        smtp_gonder($sock, "STARTTLS", "STARTTLS");
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        smtp_gonder($sock, "EHLO " . SMTP_HOST, "EHLO2");
    }

    smtp_gonder($sock, "AUTH LOGIN", "AUTH LOGIN");
    smtp_gonder($sock, base64_encode(SMTP_USER), "AUTH USER");
    $auth_yaniti = smtp_gonder($sock, base64_encode(SMTP_PASS), "AUTH PASS");

    if (strpos(trim($auth_yaniti), "235") !== 0) {
        debug_kaydet("KIMLIK DOGRULAMA BASARISIZ. Sunucu yaniti: " . trim($auth_yaniti));
        fclose($sock);
        return false;
    }
    debug_kaydet("Kimlik dogrulama basarili.");

    smtp_gonder($sock, "MAIL FROM:<" . SMTP_USER . ">", "MAIL FROM");
    smtp_gonder($sock, "RCPT TO:<" . $alici . ">", "RCPT TO");
    smtp_gonder($sock, "DATA", "DATA");

    $message_id = "<" . md5(uniqid((string)mt_rand(), true)) . "@nicebodrum.com>";

    $konu_kodlu = "=?UTF-8?B?" . base64_encode($konu) . "?=";

    $basliklar  = "Date: " . date("r") . "\r\n";
    $basliklar .= "Message-ID: " . $message_id . "\r\n";
    $basliklar .= "From: Nice Bodrum Web Sitesi <" . SMTP_USER . ">\r\n";
    $basliklar .= "To: <" . $alici . ">\r\n";
    $basliklar .= "Reply-To: " . $yanit_adresi . "\r\n";
    $basliklar .= "Subject: " . $konu_kodlu . "\r\n";
    $basliklar .= "MIME-Version: 1.0\r\n";
    $basliklar .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $basliklar .= "Content-Transfer-Encoding: base64\r\n";

    $govde_kodlu = chunk_split(base64_encode($govde), 76, "\r\n");

    $mesaj = $basliklar . "\r\n" . $govde_kodlu . ".";
    $son_yanit = smtp_gonder($sock, $mesaj, "DATA-GONDER");

    smtp_gonder($sock, "QUIT", "QUIT");
    fclose($sock);

    $basarili_mi = (strpos(trim($son_yanit), "250") === 0);
    debug_kaydet($basarili_mi ? "MAIL BASARIYLA GONDERILDI." : "MAIL GONDERILEMEDI (son yanit 250 ile baslamadi).");

    return $basarili_mi;
}

$basarili = form_maili_gonder($alici_eposta, $konu, $govde, $yanit_adresi);

$donus = !empty($_POST["_donus"]) ? $_POST["_donus"] : "/";
$ayrac = (strpos($donus, "?") !== false) ? "&" : "?";

if ($basarili) {
    header("Location: " . $donus . $ayrac . "gonderildi=1");
} else {
    header("Location: " . $donus . $ayrac . "hata=1");
}
exit;
