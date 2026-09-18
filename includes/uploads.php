<?php
/**
 * Secure image uploads.
 * - checks the upload error, size limit, real MIME type (finfo) and that it decodes as an image
 * - never trusts the original filename: files get a random name and an extension from the MIME type
 * - re-encodes with GD (strips metadata and anything hidden in the file) and scales down large images
 * Files land in /uploads/<folder>/, where PHP execution is disabled by .htaccess.
 */

const UPLOAD_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

/**
 * @return array{0: ?string, 1: ?string} [relative path like "uploads/technicians/ab12.jpg", error message]
 */
function upload_image(?array $file, string $folder, int $maxSide = 800): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'The upload failed. Please try again.'];
    }
    $max = (int) config('app.upload_max_bytes', 2 * 1024 * 1024);
    if ($file['size'] > $max) {
        return [null, 'Image is too large. Maximum is ' . round($max / 1048576, 1) . ' MB.'];
    }
    if (!preg_match('/^[a-z0-9_-]+$/', $folder)) {
        throw new InvalidArgumentException('Invalid upload folder');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(UPLOAD_IMAGE_TYPES[$mime])) {
        return [null, 'Only JPG, PNG or WEBP images are allowed.'];
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) {
        return [null, 'This file is not a valid image.'];
    }

    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$src) {
        return [null, 'This image could not be read.'];
    }

    // Scale down, keep aspect ratio and transparency
    [$w, $h] = [imagesx($src), imagesy($src)];
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $dir = ROOT_PATH . '/uploads/' . $folder;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return [null, 'Upload folder is not writable.'];
    }
    $ext = UPLOAD_IMAGE_TYPES[$mime];
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    $ok = match ($ext) {
        'jpg'  => imagejpeg($dst, "$dir/$name", 85),
        'png'  => imagepng($dst, "$dir/$name", 7),
        'webp' => imagewebp($dst, "$dir/$name", 85),
    };
    imagedestroy($src);
    imagedestroy($dst);

    return $ok ? ["uploads/$folder/$name", null] : [null, 'Could not save the image.'];
}

/** Delete a previously uploaded file (only inside /uploads). */
function upload_delete(?string $path): void
{
    if (!$path || !preg_match('#^uploads/[a-z0-9_-]+/[a-f0-9]{24}\.(jpg|png|webp)$#', $path)) {
        return;
    }
    $full = ROOT_PATH . '/' . $path;
    if (is_file($full)) {
        @unlink($full);
    }
}
