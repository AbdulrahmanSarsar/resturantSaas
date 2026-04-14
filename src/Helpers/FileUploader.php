<?php
/**
 * MenuPro — File Upload Helper
 * 
 * Replaces duplicated upload logic in dishes.php, profile.php, offers.php.
 * Handles image uploads, 3D model uploads, and file deletion.
 * 
 * Usage:
 *   $uploader = new FileUploader();
 *   $filename = $uploader->uploadImage($_FILES['image'], 'dishes');
 *   $uploader->delete('dishes', $oldFilename);
 */

namespace MenuPro\Helpers;

class FileUploader
{
    private string $baseDir;

    // Allowed extensions per file type
    private const ALLOWED_IMAGES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const ALLOWED_MODELS = ['glb'];
    private const ALLOWED_USDZ  = ['usdz'];

    // Max file sizes (bytes)
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;  // 10MB
    private const MAX_MODEL_SIZE = 50 * 1024 * 1024;   // 50MB

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir 
            ?? rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/assets/uploads/';
    }

    /**
     * Upload an image file.
     * 
     * @param array  $file      $_FILES['fieldname']
     * @param string $subfolder e.g. 'dishes', 'logos', 'offers', 'shamcash'
     * @param string|null $oldFile  Previous filename to delete
     * @return array ['success' => bool, 'filename' => string, 'error' => string]
     */
    public function uploadImage(array $file, string $subfolder, ?string $oldFile = null): array
    {
        if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'filename' => '', 'error' => 'No file uploaded'];
        }

        // Validate extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'jfif') $ext = 'jpg';
        
        if (!in_array($ext, self::ALLOWED_IMAGES, true)) {
            return ['success' => false, 'filename' => '', 'error' => 'امتداد غير مدعوم: ' . $ext];
        }

        // Validate size
        if ($file['size'] > self::MAX_IMAGE_SIZE) {
            return ['success' => false, 'filename' => '', 'error' => 'حجم الملف كبير جداً (الحد الأقصى 10MB)'];
        }

        // Ensure directory exists
        $dir = $this->baseDir . $subfolder . '/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Delete old file if exists
        if ($oldFile) {
            $this->delete($subfolder, $oldFile);
        }

        // Generate unique filename
        $newName = uniqid($subfolder . '_', true) . '.' . $ext;
        
        if (move_uploaded_file($file['tmp_name'], $dir . $newName)) {
            return ['success' => true, 'filename' => $newName, 'error' => ''];
        }

        return ['success' => false, 'filename' => '', 'error' => 'فشل رفع الصورة — تحقق من صلاحيات المجلد'];
    }

    /**
     * Upload a 3D model file (.glb).
     */
    public function uploadModel(array $file, ?string $oldFile = null): array
    {
        if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'filename' => '', 'error' => ''];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_MODELS, true)) {
            return ['success' => false, 'filename' => '', 'error' => 'امتداد غير مدعوم: ' . $ext];
        }

        $dir = $this->baseDir . 'models3d/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if ($oldFile) $this->delete('models3d', $oldFile);

        $newName = uniqid() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $dir . $newName)) {
            return ['success' => true, 'filename' => $newName, 'error' => ''];
        }

        return ['success' => false, 'filename' => '', 'error' => 'فشل رفع النموذج'];
    }

    /**
     * Upload a USDZ file (iOS AR).
     */
    public function uploadUsdz(array $file, ?string $oldFile = null): array
    {
        if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'filename' => '', 'error' => ''];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_USDZ, true)) {
            return ['success' => false, 'filename' => '', 'error' => 'امتداد غير مدعوم: ' . $ext];
        }

        $dir = $this->baseDir . 'models3d/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        if ($oldFile) $this->delete('models3d', $oldFile);

        $newName = uniqid() . '.usdz';
        if (move_uploaded_file($file['tmp_name'], $dir . $newName)) {
            return ['success' => true, 'filename' => $newName, 'error' => ''];
        }

        return ['success' => false, 'filename' => '', 'error' => 'فشل رفع الملف'];
    }

    /**
     * Delete a file.
     */
    public function delete(string $subfolder, string $filename): bool
    {
        if (!$filename) return false;
        
        $path = $this->baseDir . $subfolder . '/' . $filename;
        if (file_exists($path)) {
            return @unlink($path);
        }
        return false;
    }
}