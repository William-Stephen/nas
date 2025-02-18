<?php
session_start();

// Cấu hình hệ thống
$upload_dir = 'C:/nas_storage/';
$max_file_size = 1024 * 1024 * 1024 * 5; // 5GB
$log_file = 'C:/nas_storage/log.txt';
$users = [
    'admin' => password_hash('admin123', PASSWORD_BCRYPT),
];

// Cấu hình PHP động
ini_set('upload_max_filesize', '5G');
ini_set('post_max_size', '5G');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);

// Hàm ghi log
function log_activity($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}

// Hàm tạo CSRF token
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Hàm kiểm tra CSRF token
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Xử lý đăng nhập
if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    if (isset($users[$username]) && password_verify($password, $users[$username])) {
        $_SESSION['username'] = $username;
        session_regenerate_id(true);
        log_activity("User $username logged in.");
    } else {
        die("Đăng nhập thất bại");
    }
}

// Xử lý đăng xuất
if (isset($_GET['logout'])) {
    log_activity("User {$_SESSION['username']} logged out.");
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Hàm tính kích thước thư mục
function calculate_folder_size($path) {
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

// Xử lý upload file hoặc thư mục
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['files'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token không hợp lệ");
    }

    $subdir = isset($_POST['subdir']) ? trim($_POST['subdir'], '/') . '/' : '';
    $target_dir = $upload_dir . $subdir;

    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

    $uploaded_files = [];
    $errors = [];

    foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
        $file_name = $_FILES['files']['name'][$key];
        $file_size = $_FILES['files']['size'][$key];
        $file_tmp = $_FILES['files']['tmp_name'][$key];
        $file_error = $_FILES['files']['error'][$key];

        if ($file_error !== UPLOAD_ERR_OK) {
            $errors[] = "Lỗi khi upload file $file_name";
            continue;
        }

        if ($file_size > $max_file_size) {
            $errors[] = "File $file_name vượt quá kích thước cho phép (5GB)";
            continue;
        }

        $new_file_name = uniqid() . '_' . basename($file_name);
        $target_path = $target_dir . $new_file_name;

        if (move_uploaded_file($file_tmp, $target_path)) {
            $uploaded_files[] = $new_file_name;
            log_activity("User {$_SESSION['username']} uploaded: $subdir$new_file_name");
        } else {
            $errors[] = "Không thể upload file $file_name";
        }
    }

    if (!empty($uploaded_files)) {
        $_SESSION['upload_success'] = implode(', ', $uploaded_files);
    }
    if (!empty($errors)) {
        $_SESSION['upload_error'] = implode('<br>', $errors);
    }
    header("Location: " . $_SERVER['PHP_SELF'] . (isset($_GET['subdir']) ? '?subdir=' . urlencode($_GET['subdir']) : ''));
    exit;
}

// Xử lý download file
if (isset($_GET['download'])) {
    $file_path = $upload_dir . basename($_GET['download']);
    
    if (file_exists($file_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($file_path).'"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        log_activity("User {$_SESSION['username']} downloaded: " . basename($file_path));
        exit;
    }
}

// Xử lý xóa file (Sửa thành POST và thêm CSRF token)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token không hợp lệ");
    }

    $file_path = $upload_dir . basename($_POST['delete']);
    
    if (file_exists($file_path) && unlink($file_path)) {
        log_activity("User {$_SESSION['username']} deleted: " . basename($file_path));
        $_SESSION['delete_success'] = 'Đã xóa file thành công';
    } else {
        $_SESSION['delete_error'] = 'Xóa file thất bại';
    }
    header("Location: ".$_SERVER['HTTP_REFERER']);
    exit;
}

// Xử lý tạo thư mục (Thêm kiểm tra directory traversal)
if (isset($_POST['create_dir'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) {
        die("CSRF token không hợp lệ");
    }

    $subdir = isset($_POST['subdir']) ? trim($_POST['subdir'], '/') . '/' : '';
    $new_dir_name = trim(basename($_POST['new_dir']), '/');
    $new_dir = $upload_dir . $subdir . $new_dir_name . '/';
    
    if (!is_dir($new_dir)) {
        mkdir($new_dir, 0777, true);
        log_activity("User {$_SESSION['username']} created directory: $new_dir");
        $_SESSION['create_dir_success'] = 'Đã tạo thư mục thành công';
    } else {
        $_SESSION['create_dir_error'] = 'Thư mục đã tồn tại';
    }
    header("Location: " . $_SERVER['PHP_SELF'] . (isset($_GET['subdir']) ? '?subdir=' . urlencode($_GET['subdir']) : ''));
    exit;
}

// Xử lý preview media
if (isset($_GET['preview'])) {
    $file_name = basename($_GET['preview']);
    $file_path = $upload_dir . $file_name;
    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    $allowed_images = ['jpg', 'jpeg', 'png', 'gif'];
    $allowed_videos = ['mp4', 'webm'];
    
    if (file_exists($file_path)) {
        if (in_array($file_ext, $allowed_images)) {
            header('Content-Type: image/' . ($file_ext === 'jpg' ? 'jpeg' : $file_ext));
            readfile($file_path);
            exit;
        } elseif (in_array($file_ext, $allowed_videos)) {
            header('Content-Type: video/' . $file_ext);
            readfile($file_path);
            exit;
        }
    }
    die("File không tồn tại hoặc định dạng không được hỗ trợ");
}

// Hàm hiển thị file
function display_files($dir) {
    $current_subdir = isset($_GET['subdir']) ? trim($_GET['subdir'], '/') : '';
    $parent_dir = dirname($current_subdir);

    if ($current_subdir !== '') {
        echo '<div class="navigation">
                <a href="?subdir=' . urlencode($parent_dir) . '" class="btn btn-open">
                    <i class="fas fa-arrow-left"></i> Quay lại
                </a>
              </div>';
    }

    $full_dir = $dir . $current_subdir . '/';
    if (!is_dir($full_dir)) return;

    $files = scandir($full_dir);
    foreach (array_diff($files, ['.', '..']) as $file) {
        $file_path = $full_dir . $file;
        $is_dir = is_dir($file_path);
        $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif']);
        $is_video = in_array($file_ext, ['mp4', 'webm']);

        echo '<div class="file-item">
                <div class="file-icon">' .
                    ($is_dir ? '<i class="fas fa-folder"></i>' :
                        ($is_image ? '<i class="fas fa-image"></i>' :
                            ($is_video ? '<i class="fas fa-video"></i>' :
                                '<i class="fas fa-file"></i>'))) .
                '</div>
                <div class="file-name">' . htmlspecialchars($file) . ($is_dir ? '/' : '') . '</div>
                <div class="file-actions">' .
                    (!$is_dir ?
                        ($is_image || $is_video ?
                            '<a href="?preview=' . urlencode($file) . '" class="btn-preview" target="_blank"><i class="fas fa-eye"></i></a>' : '') .
                        '<a href="?download=' . urlencode($file) . '" class="btn-download"><i class="fas fa-download"></i></a>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="' . generate_csrf_token() . '">
                            <input type="hidden" name="delete" value="' . htmlspecialchars($file) . '">
                            <button type="submit" class="btn-delete" onclick="return confirm(\'Xóa file này?\')"><i class="fas fa-trash"></i></button>
                        </form>' :
                        '<a href="?subdir=' . urlencode($current_subdir . '/' . $file) . '" class="btn-open"><i class="fas fa-folder-open"></i></a>') .
                '</div>
              </div>';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NAS System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f0f2f5;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 25px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 0;
            border-bottom: 1px solid #eee;
            margin-bottom: 25px;
        }

        .file-manager {
            display: grid;
            gap: 25px;
        }

        .upload-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .file-list {
            background: #fff;
            border-radius: 8px;
            padding: 15px;
        }

        .file-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: #fff;
            border-radius: 8px;
            transition: all 0.2s;
            margin-bottom: 8px;
            border: 1px solid #eee;
        }

        .file-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.08);
        }

        .file-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: #4a90e2;
            font-size: 1.2rem;
        }

        .file-name {
            flex-grow: 1;
            color: #333;
            font-weight: 500;
            font-size: 0.95rem;
        }

        .file-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }

        .btn-download {
            background: #4a90e2;
            color: white;
        }

        .btn-delete {
            background: #e74c3c;
            color: white;
        }

        .btn-open {
            background: #2ecc71;
            color: white;
        }

        .btn-preview {
            background: #f1c40f;
            color: white;
        }

        .btn:hover {
            opacity: 0.85;
        }

        .login-box {
            max-width: 400px;
            margin: 50px auto;
            padding: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }

        .toast {
            position: fixed;
            bottom: 25px;
            right: 25px;
            padding: 15px 25px;
            border-radius: 8px;
            color: white;
            background: #4CAF50;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            animation: slideIn 0.4s ease-out;
            z-index: 1000;
        }

        .toast.error {
            background: #f44336;
        }

        @keyframes slideIn {
            from { transform: translateX(100%); }
            to { transform: translateX(0); }
        }

        .preview-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .preview-content {
            max-width: 90%;
            max-height: 90vh;
            background: #000;
            border-radius: 8px;
            position: relative;
            padding: 15px;
        }

        #preview-media {
            max-width: 100%;
            max-height: 80vh;
            border-radius: 4px;
        }

        .close-preview {
            position: absolute;
            top: 15px;
            right: 15px;
            color: white;
            font-size: 28px;
            cursor: pointer;
            padding: 5px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .file-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .file-actions {
                width: 100%;
                justify-content: space-between;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .login-box {
                padding: 20px;
                margin: 20px;
            }
            
            .file-icon {
                width: 35px;
                height: 35px;
                font-size: 1rem;
            }
            
            .btn {
                font-size: 0.85rem;
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <?php if (!isset($_SESSION['username'])): ?>
    <div class="login-box">
        <h2 style="text-align: center; margin-bottom: 1.5rem;">NAS Login</h2>
        <form method="post">
            <div style="margin-bottom: 1rem;">
                <input type="text" name="username" placeholder="Username" required 
                    style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            <div style="margin-bottom: 1.5rem;">
                <input type="password" name="password" placeholder="Password" required 
                    style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 6px;">
            </div>
            <button type="submit" name="login" class="btn" 
                style="width: 100%; background: #4a90e2; color: white;">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>
    </div>
    <?php else: ?>
    <div class="container">
        <?php if (isset($_SESSION['upload_success'])): ?>
        <div class="toast">
            Upload thành công: <?= htmlspecialchars($_SESSION['upload_success']) ?>
            <?php unset($_SESSION['upload_success']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['upload_error'])): ?>
        <div class="toast error">
            <?= $_SESSION['upload_error'] ?>
            <?php unset($_SESSION['upload_error']); ?>
        </div>
        <?php endif; ?>

        <div class="header">
            <h1><i class="fas fa-server"></i> NAS System</h1>
            <div style="display: flex; gap: 1rem; align-items: center;">
            <span style="color: #666;">Xin chào, <?= htmlspecialchars($_SESSION['username']) ?></span>
                <a href="?logout=1" class="btn btn-delete"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
        
        <div class="file-manager">
            <div class="upload-section">
                <form method="post" enctype="multipart/form-data" style="display: flex; gap: 1rem; align-items: center;">
                    <input type="file" name="files[]" multiple required 
                        style="flex-grow: 1; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px;">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <button type="submit" class="btn btn-download" style="padding: 0.8rem 1.5rem;">
                        <i class="fas fa-upload"></i> Upload
                    </button>
                </form>
            </div>
            
            <div class="file-list">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                    <h3 style="font-size: 1.2rem;">Danh sách file</h3>
                    <form method="post" style="display: flex; gap: 0.5rem;">
                        <input type="text" name="new_dir" placeholder="Tên thư mục mới" 
                            style="padding: 0.5rem; border: 1px solid #ddd; border-radius: 6px;">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <button type="submit" name="create_dir" class="btn btn-open" style="padding: 0.5rem 1rem;">
                            <i class="fas fa-folder-plus"></i> Tạo thư mục
                        </button>
                    </form>
                </div>
                <?php display_files($upload_dir); ?>
            </div>
        </div>
    </div>

    <script>
        // Xử lý tự động ẩn thông báo
        document.addEventListener('DOMContentLoaded', function() {
            const toasts = document.querySelectorAll('.toast');
            toasts.forEach(toast => {
                setTimeout(() => {
                    toast.style.opacity = '0';
                    setTimeout(() => toast.remove(), 300);
                }, 3000);
            });

            // Xử lý preview media
            document.querySelectorAll('.btn-preview').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const mediaUrl = this.href;
                    
                    const overlay = document.createElement('div');
                    overlay.className = 'preview-overlay';
                    overlay.innerHTML = `
                        <div class="preview-content">
                            <span class="close-preview">&times;</span>
                            ${mediaUrl.includes('.mp4') || mediaUrl.includes('.webm') 
                                ? `<video controls src="${mediaUrl}" class="preview-media"></video>`
                                : `<img src="${mediaUrl}" class="preview-media">`}
                        </div>
                    `;
                    
                    document.body.appendChild(overlay);
                    
                    overlay.querySelector('.close-preview').addEventListener('click', () => {
                        overlay.remove();
                    });
                });
            });

            // Xử lý hover cho mobile
            if ('ontouchstart' in window) {
                document.querySelectorAll('.file-item').forEach(item => {
                    item.style.transition = 'none';
                });
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>