<?php
declare(strict_types=1);

namespace Nooky\Chat;

/**
 * Fotos und Profilbilder.
 *
 * Jedes Bild wird mit GD neu gerechnet und neu gespeichert. Dadurch fallen
 * alle Metadaten weg (EXIF, GPS-Position, Kamera), und kaputte oder
 * präparierte Dateien kommen gar nicht erst auf die Platte. Die Dateien
 * liegen in data/uploads (außerhalb des Document Roots) und werden nur über
 * public/media.php nach einer Rechteprüfung ausgeliefert.
 */
final class Media
{
    public const MAX_UPLOAD = 10 * 1024 * 1024;   // 10 MB pro Datei
    private const MAX_PIXELS = 30_000_000;         // Schutz vor „Pixel-Bomben“
    private const PHOTO_MAX  = 1600;               // längste Kante für Chat-Fotos
    private const AVATAR     = 256;                // Profilbild: quadratisch

    /** Dateiname: 32 Hex-Zeichen + Endung. */
    public const NAME_PATTERN = '/^[a-f0-9]{32}\.(webp|jpg)$/';

    public static function dir(): string
    {
        $dir = dirname(__DIR__) . '/data/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    /**
     * Speichert ein hochgeladenes Chat-Foto.
     * Gibt ['file' => …, 'w' => …, 'h' => …] zurück; $error bei Misserfolg.
     */
    public static function storePhoto(array $upload, ?string &$error = null): ?array
    {
        $img = self::load($upload, $error);
        if ($img === null) {
            return null;
        }
        $img = self::fit($img, self::PHOTO_MAX);
        return self::save($img, $error);
    }

    /** Speichert ein Profilbild (quadratisch zugeschnitten). Gibt den Dateinamen zurück. */
    public static function storeAvatar(array $upload, ?string &$error = null): ?string
    {
        $img = self::load($upload, $error);
        if ($img === null) {
            return null;
        }
        $img = self::square($img, self::AVATAR);
        return self::save($img, $error)['file'] ?? null;
    }

    public static function delete(?string $file): void
    {
        if ($file !== null && preg_match(self::NAME_PATTERN, $file)) {
            @unlink(self::dir() . '/' . $file);
        }
    }

    /** Liefert eine Datei aus (Rechte hat der Aufrufer geprüft). */
    public static function send(string $file): never
    {
        $path = self::dir() . '/' . $file;
        if (!preg_match(self::NAME_PATTERN, $file) || !is_file($path)) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . (str_ends_with($file, '.webp') ? 'image/webp' : 'image/jpeg'));
        header('Content-Length: ' . filesize($path));
        // Namen sind zufällig und werden nie wiederverwendet → lange cachen.
        header('Cache-Control: private, max-age=31536000, immutable');
        readfile($path);
        exit;
    }

    // ---- intern ---------------------------------------------------------

    private static function load(array $upload, ?string &$error): ?\GdImage
    {
        $error = null;
        $err   = $upload['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
            || ($err === UPLOAD_ERR_OK && (int) $upload['size'] > self::MAX_UPLOAD)) {
            $error = 'Das Bild ist zu groß (max. 10 MB).';
            return null;
        }
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
            $error = 'Upload fehlgeschlagen.';
            return null;
        }

        $tmp  = (string) $upload['tmp_name'];
        $info = @getimagesize($tmp);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            $error = 'Nur JPG, PNG, GIF oder WebP.';
            return null;
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            $error = 'Das Bild hat zu viele Pixel.';
            return null;
        }

        ini_set('memory_limit', '256M');
        $img = @imagecreatefromstring((string) file_get_contents($tmp));
        if ($img === false) {
            $error = 'Das Bild konnte nicht gelesen werden.';
            return null;
        }

        // Handyfotos: Drehung aus EXIF übernehmen, bevor EXIF wegfällt.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            $rot  = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($rot !== 0) {
                $img = imagerotate($img, $rot, 0) ?: $img;
            }
        }

        // Transparenz auf dunklen Hintergrund legen (passt zum Chat).
        $w = imagesx($img);
        $h = imagesy($img);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 0x1a, 0x18, 0x20));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        return $flat;
    }

    private static function fit(\GdImage $img, int $max): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $max / max($w, $h));
        if ($scale >= 1) {
            return $img;
        }
        return imagescale($img, (int) round($w * $scale), (int) round($h * $scale), IMG_BICUBIC) ?: $img;
    }

    private static function square(\GdImage $img, int $size): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $side = min($w, $h);
        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $img, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
        return $out;
    }

    private static function save(\GdImage $img, ?string &$error): ?array
    {
        $webp = function_exists('imagewebp');
        $file = bin2hex(random_bytes(16)) . ($webp ? '.webp' : '.jpg');
        $path = self::dir() . '/' . $file;

        $ok = $webp ? imagewebp($img, $path, 80) : imagejpeg($img, $path, 82);
        if (!$ok) {
            $error = 'Das Bild konnte nicht gespeichert werden.';
            return null;
        }
        @chmod($path, 0640);
        return ['file' => $file, 'w' => imagesx($img), 'h' => imagesy($img)];
    }
}
