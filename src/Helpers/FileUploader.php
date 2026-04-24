<?php
/**
 * MenuPro — File Upload Helper
 *
 * Replaces duplicated upload logic in dishes.php, profile.php, offers.php.
 * Handles image uploads, 3D model uploads, and file deletion.
 *
 * Security hardening:
 *   - Extension whitelist + MIME check via finfo (prevents extension spoofing)
 *   - Image validation via getimagesize() (for image uploads)
 *   - .htaccess drop on first write denies PHP execution in uploads/
 *   - Safe subfolder sanitation (no path traversal)
 *   - Random filename using bin2hex(random_bytes) — no user input in path
 *
 * Usage:
 *   $uploader = new FileUploader();
 *   $res = $uploader->uploadImage($_FILES['image'], 'dishes');
 *   if ($res['success']) $filename = $res['filename'];
 *   $uploader->delete('dishes', $oldFilename);
 */

namespace MenuPro\Helpers;

class FileUploader
{
    private string $baseDir;

    // Allowed extensions per file type
    private const ALLOWED_IMAGES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const ALLOWED_MODELS = ['glb'];
    private const ALLOWED_USDZ   = ['usdz'];

    // MIME whitelist — finfo must return one of these
    private const MIME_IMAGES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];
    private const MIME_MODELS = [
        'model/gltf-binary',
        'application/octet-stream', // glb often reported as this
    ];
    private const MIME_USDZ = [
        'model/vnd.usdz+zip',
        'application/zip',
        'application/octet-stream',
    ];

    // Max file sizes (bytes)
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;   // 10MB
    private const MAX_MODEL_SIZE = 50 * 1024 * 1024;   // 50MB

    // Contents of the .htaccess we drop into every uploads subfolder.
    // Kills PHP execution even if a malicious file slips through.
    private const HTACCESS_GUARD = <<<'HTACCESS'
# MenuPro — upload directory guard.
# Deny execution of any script here; serve only static assets.
<FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phps|pht|cgi|pl|py|rb|sh|asp|aspx|jsp)$">
    Require all denied
</FilesMatch>
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>
Options -ExecCGI -Indexes
AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .pht
HTACCESS;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir
            ?? rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/assets/uploads/';
    }

    /**
     * Upload an image file.
     *
     * @param array       $file      $_FILES['fieldname']
     * @param string      $subfolder e.g. 'dishes', 'logos', 'offers', 'shamcash'
     * @param string|null $oldFile   Previous filename to delete
     * @return array ['success' => bool, 'filename' => string, 'error' => string]
     */
    public function uploadImage(array $file, string $subfolder, ?string $oldFile = null): array
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail('No file uploaded');
        }

        // Validate extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'jfif') $ext = 'jpg';

        if (!in_array($ext, self::ALLOWED_IMAGES, true)) {
            return $this->fail('امتداد غير مدعوم: ' . $ext);
        }

        // Validate size
        if ($file['size'] > self::MAX_IMAGE_SIZE) {
            return $this->fail('حجم الملف كبير جداً (الحد الأقصى 10MB)');
        }

        // Validate MIME via finfo — prevents .jpg with PHP payload
        $mime = $this->detectMime($file['tmp_name']);
        if (!$this->mimeAllowed($mime, self::MIME_IMAGES)) {
            return $this->fail('نوع الملف غير صالح كصورة');
        }

        // Extra image-integrity check — getimagesize rejects non-images
        if (@getimagesize($file['tmp_name']) === false) {
            return $this->fail('الملف ليس صورة صالحة');
        }

        // Prepare destination
        $dir = $this->prepareSubfolder($subfolder);
        if ($dir === null) {
            return $this->fail('مجلد غير صالح');
        }

        // Delete old file if exists
        if ($oldFile) {
            $this->delete($subfolder, $oldFile);
        }

        // Generate random filename — no user input in path
        $newName = $this->randomFilename($subfolder, $ext);

        if (move_uploaded_file($file['tmp_name'], $dir . $newName)) {
            @chmod($dir . $newName, 0644);
            return ['success' => true, 'filename' => $newName, 'error' => ''];
        }

        return $this->fail('فشل رفع الصورة — تحقق من صلاحيات المجلد');
    }

    /**
     * Upload a 3D model file (.glb).
     */
    public function uploadModel(array $file, ?string $oldFile = null): array
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail('');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_MODELS, true)) {
            return $this->fail('امتداد غير مدعوم: ' . $ext);
        }

        if ($file['size'] > self::MAX_MODEL_SIZE) {
            return $this->fail('حجم الملف كبير جداً (الحد الأقصى 50MB)');
        }

        $mime = $this->detectMime($file['tmp_name']);
        if (!$this->mimeAllowed($mime, self::MIME_MODELS)) {
            return $this->fail('نوع الملف غير صالح كنموذج ثلاثي الأبعاد');
        }

        // GLB signature check: first 4 bytes = "glTF"
        if (!$this->hasMagicBytes($file['tmp_name'], "glTF")) {
            return $this->fail('الملف ليس GLB صالح');
        }

        $dir = $this->prepareSubfolder('models3d');
        if ($dir === null) return $this->fail('مجلد غير صالح');

        if ($oldFile) $this->delete('models3d', $oldFile);

        $newName = $this->randomFilename('model', $ext);
        if (move_uploaded_file($file['tmp_name'], $dir . $newName)) {
            @chmod($dir . $newName, 0644);
            return ['success' => true, 'filename' => $newName, 'error' => ''];
        }

        return $this->fail('فشل رفع النموذج');
    }

    /**
     * Upload a USDZ file (iOS AR).
     */
    public function uploadUsdz(array $file, ?string $oldFile = null): array
    {
        if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->fail('');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_USDZ, true)) {
            return $this->fail('امتداد غير مدعوم: ' . $ext);
        }

        if ($file['size'] > self::MAX_MODEL_SIZE) {
            return $this->fail('حجم الملف كبير جداً (الحد الأقصى 50MB)');
        }

        $mime = $this->detectMime($file['tmp_name']);
        if (!$this->mimeAllowed($mime, self::MIME_USDZ)) {
            return $this->fail('نوع الملف غير صالح');
        }

        // USDZ is a zip — first 2 bytes are "PK"
        if (!$this->hasMagicBytes($file['tmp_name'], "PK")) {
            return $this->fail('الملف ليس USDZ صالح');
        }

        $dir = $this->prepareSubfolder('models3d');
        if ($dir === null) return $this->fail('مجلد غير صالح');

        if ($oldFile) $this->delete('models3d', $oldFile);

        $newName = $this->randomFilename('model', 'usdz');
        if (move_uploaded_file($file['tmp_name'], $dir . $newName)) {
            @chmod($dir . $newName, 0644);
            return ['success' => true, 'filename' => $newName, 'error' => ''];
        }

        return $this->fail('فشل رفع الملف');
    }

    /**
     * Delete a file — refuses path-traversal attempts.
     */
    public function delete(string $subfolder, string $filename): bool
    {
        if (!$filename) return false;

        // Strip any directory components — we only accept a bare filename.
        $safeName = basename($filename);
        if ($safeName === '' || $safeName !== $filename) {
            return false;
        }

        $safeSub = $this->sanitizeSubfolder($subfolder);
        if ($safeSub === null) return false;

        $path = $this->baseDir . $safeSub . '/' . $safeName;
        $real = realpath($path);
        $rootReal = realpath($this->baseDir);

        // Defensive: ensure the resolved path still sits under baseDir.
        if ($real === false || $rootReal === false || strpos($real, $rootReal) !== 0) {
            return false;
        }

        if (file_exists($real)) {
            return @unlink($real);
        }
        return false;
    }

    // ────────────────────────────────────────────────────────────────────
    //   Internals
    // ────────────────────────────────────────────────────────────────────

    private function fail(string $msg): array
    {
        return ['success' => false, 'filename' => '', 'error' => $msg];
    }

    private function detectMime(string $tmpPath): string
    {
        if (!function_exists('finfo_open')) {
            return ''; // finfo unavailable — skip MIME step (host misconfig)
        }
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if (!$fi) return '';
        $mime = @finfo_file($fi, $tmpPath) ?: '';
        @finfo_close($fi);
        return strtolower($mime);
    }

    private function mimeAllowed(string $mime, array $whitelist): bool
    {
        // Empty mime = finfo unavailable on host — fall back to extension only.
        if ($mime === '') return true;
        return in_array($mime, $whitelist, true);
    }

    private function hasMagicBytes(string $path, string $magic): bool
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) return false;
        $buf = @fread($fp, strlen($magic));
        @fclose($fp);
        return $buf === $magic;
    }

    /**
     * Only permit simple names — prevents "dishes/../../" style traversal.
     */
    private function sanitizeSubfolder(string $subfolder): ?string
    {
        $subfolder = trim($subfolder, "/\\ \t\r\n");
        if ($subfolder === '') return null;
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $subfolder)) return null;
        return $subfolder;
    }

    /**
     * Ensure the subfolder exists + drop the .htaccess guard on first use.
     */
    private function prepareSubfolder(string $subfolder): ?string
    {
        $safe = $this->sanitizeSubfolder($subfolder);
        if ($safe === null) return null;

        $dir = $this->baseDir . $safe . '/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Drop the guard on baseDir and the subfolder (idempotent).
        $this->ensureHtaccess($this->baseDir);
        $this->ensureHtaccess($dir);

        return $dir;
    }

    private function ensureHtaccess(string $dir): void
    {
        if (!is_dir($dir)) return;
        $path = rtrim($dir, '/\\') . '/.htaccess';
        if (file_exists($path)) return;
        @file_put_contents($path, self::HTACCESS_GUARD);
        @chmod($path, 0644);
    }

    private function randomFilename(string $prefix, string $ext): string
    {
        try {
            $rand = bin2hex(random_bytes(12));
        } catch (\Throwable $e) {
            // fallback if random_bytes unavailable
            $rand = bin2hex(openssl_random_pseudo_bytes(12));
        }
        $prefix = preg_replace('/[^a-zA-Z0-9_\-]/', '', $prefix) ?: 'file';
        $ext    = preg_replace('/[^a-zA-Z0-9]/', '', $ext) ?: 'bin';
        return $prefix . '_' . time() . '_' . $rand . '.' . $ext;
    }
}
