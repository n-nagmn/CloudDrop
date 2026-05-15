<?php
$uploadDir = "uploads/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$message = "";
$status = "";

// ファイル削除処理
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "delete") {
    $filename = basename($_POST["filename"]);
    $filePath = $uploadDir . $filename;
    if (file_exists($filePath)) {
        if (unlink($filePath)) {
            $message = "ファイル「" . htmlspecialchars($filename) . "」を削除しました。";
            $status = "success";
        } else {
            $message = "ファイルの削除に失敗しました。";
            $status = "error";
        }
    }
}

// ファイルアップロード処理
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["file"])) {
    $file = $_FILES["file"];
    $targetPath = $uploadDir . basename($file["name"]);
    
    if (move_uploaded_file($file["tmp_name"], $targetPath)) {
        $message = "ファイル「" . htmlspecialchars($file["name"]) . "」をアップロードしました。";
        $status = "success";
    } else {
        $message = "アップロードに失敗しました。";
        $status = "error";
    }
}

// サーバーのURLベースを取得
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$currentDir = dirname($_SERVER['PHP_SELF']);
$baseUrl = $protocol . "://" . $host . rtrim($currentDir, '/') . "/uploads/";
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudDrop - Simple File Sharing</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="app-container">
        <header>
            <div class="logo">
                <i class="fas fa-cloud-arrow-up"></i>
                <span>CloudDrop</span>
            </div>
        </header>

        <main>
            <section class="upload-card">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $status; ?>">
                        <i class="fas <?php echo $status === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <form action="" method="post" enctype="multipart/form-data" id="upload-form">
                    <div class="drop-zone" id="drop-zone">
                        <div class="drop-zone-content">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p>ファイルをドラッグ＆ドロップするか、<br><span>クリックして選択</span></p>
                            <input type="file" name="file" id="file-input" required>
                        </div>
                        <div id="file-info" class="file-info"></div>
                    </div>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-arrow-up"></i> アップロード開始
                    </button>
                </form>
            </section>

            <section class="files-card">
                <div class="card-header">
                    <h2><i class="fas fa-list"></i> 最近のアップロード</h2>
                </div>
                <div class="file-table-container">
                    <table class="file-table">
                        <thead>
                            <tr>
                                <th>ファイル名</th>
                                <th>サイズ</th>
                                <th>日付</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $files = array_diff(scandir($uploadDir), array(".", ".."));
                            $fileDetails = [];
                            foreach ($files as $f) {
                                $path = $uploadDir . $f;
                                if (is_file($path)) {
                                    $fileDetails[] = [
                                        'name' => $f,
                                        'size' => filesize($path),
                                        'time' => filemtime($path)
                                    ];
                                }
                            }
                            
                            usort($fileDetails, function($a, $b) {
                                return $b['time'] - $a['time'];
                            });

                            function formatBytes($bytes, $precision = 2) {
                                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                                $bytes = max($bytes, 0);
                                $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
                                $pow = min($pow, count($units) - 1);
                                $bytes /= pow(1024, $pow);
                                return round($bytes, $precision) . ' ' . $units[$pow];
                            }

                            if (empty($fileDetails)): ?>
                                <tr>
                                    <td colspan="4" class="empty-state">ファイルがありません</td>
                                </tr>
                            <?php else:
                                foreach ($fileDetails as $f): 
                                    $fileUrl = $baseUrl . rawurlencode($f['name']);
                                ?>
                                    <tr>
                                        <td class="file-name">
                                            <i class="far fa-file"></i> <?php echo htmlspecialchars($f['name']); ?>
                                        </td>
                                        <td><?php echo formatBytes($f['size']); ?></td>
                                        <td><?php echo date("Y/m/d H:i", $f['time']); ?></td>
                                        <td class="actions">
                                            <div class="action-group">
                                                <a href="uploads/<?php echo rawurlencode($f['name']); ?>" class="btn-icon" target="_blank" title="ダウンロード">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <button class="btn-icon copy-btn" data-url="<?php echo $fileUrl; ?>" title="リンクをコピー">
                                                    <i class="fas fa-link"></i>
                                                </button>
                                                <form action="" method="post" onsubmit="return confirm('本当に削除しますか？');" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="filename" value="<?php echo htmlspecialchars($f['name']); ?>">
                                                    <button type="submit" class="btn-icon delete-btn" title="削除">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>

        <footer>
            <p>&copy; <?php echo date("Y"); ?> CloudDrop. Built with speed.</p>
        </footer>
    </div>

    <!-- トースト通知用 -->
    <div id="toast" class="toast">コピーしました！</div>

    <script>
        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');
        const fileInfo = document.getElementById('file-info');
        const toast = document.getElementById('toast');

        dropZone.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                updateFileInfo(fileInput.files[0]);
            }
        });

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        });

        ['dragleave', 'dragend'].forEach(type => {
            dropZone.addEventListener(type, () => {
                dropZone.classList.remove('drag-over');
            });
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
            
            if (e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                updateFileInfo(e.dataTransfer.files[0]);
            }
        });

        function updateFileInfo(file) {
            fileInfo.textContent = `選択中: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
            fileInfo.style.display = 'block';
        }

        // コピー機能
        document.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const url = btn.getAttribute('data-url');
                try {
                    await navigator.clipboard.writeText(url);
                    showToast();
                } catch (err) {
                    console.error('Failed to copy: ', err);
                }
            });
        });

        function showToast() {
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 2000);
        }
    </script>
</body>
</html>
