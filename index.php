<?php
date_default_timezone_set('Asia/Tokyo');
session_start();

$uploadDir = "uploads/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$message = isset($_SESSION['message']) ? $_SESSION['message'] : "";
$status = isset($_SESSION['status']) ? $_SESSION['status'] : "";
unset($_SESSION['message'], $_SESSION['status']);

// ファイル削除処理
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "delete") {
    $filename = basename($_POST["filename"]);
    $filePath = $uploadDir . $filename;
    if (file_exists($filePath)) {
        if (unlink($filePath)) {
            $_SESSION['message'] = "ファイル「" . htmlspecialchars($filename) . "」を削除しました。";
            $_SESSION['status'] = "success";
        } else {
            $_SESSION['message'] = "ファイルの削除に失敗しました。";
            $_SESSION['status'] = "error";
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ファイル名前変更処理
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "rename") {
    $filename = basename($_POST["filename"]);
    $new_filename = basename($_POST["new_filename"]);
    
    if ($new_filename !== "") {
        $oldFilePath = $uploadDir . $filename;
        $newFilePath = $uploadDir . $new_filename;
        
        if (file_exists($oldFilePath) && !file_exists($newFilePath)) {
            if (rename($oldFilePath, $newFilePath)) {
                $_SESSION['message'] = "ファイル名を「" . htmlspecialchars($new_filename) . "」に変更しました。";
                $_SESSION['status'] = "success";
            } else {
                $_SESSION['message'] = "ファイル名の変更に失敗しました。";
                $_SESSION['status'] = "error";
            }
        } else {
            $_SESSION['message'] = "ファイルが存在しないか、同じ名前のファイルが既に存在します。";
            $_SESSION['status'] = "error";
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ファイルアップロード処理（複数対応）
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["files"])) {
    $files = $_FILES["files"];
    $uploadedCount = 0;
    $errors = 0;

    if (isset($files["name"]) && is_array($files["name"])) {
        $fileCount = count($files["name"]);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files["error"][$i] === UPLOAD_ERR_OK) {
                $targetPath = $uploadDir . basename($files["name"][$i]);
                if (move_uploaded_file($files["tmp_name"][$i], $targetPath)) {
                    $uploadedCount++;
                } else {
                    $errors++;
                }
            } else if ($files["error"][$i] !== UPLOAD_ERR_NO_FILE) {
                $errors++;
            }
        }
    }

    if ($uploadedCount > 0) {
        $_SESSION['message'] = $uploadedCount . "件のファイルをアップロードしました。";
        if ($errors > 0) $_SESSION['message'] .= "（" . $errors . "件失敗）";
        $_SESSION['status'] = "success";
    } else if ($errors > 0) {
        $_SESSION['message'] = "アップロードに失敗しました。";
        $_SESSION['status'] = "error";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
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
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
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
                            <input type="file" name="files[]" id="file-input" multiple style="display: none;">
                        </div>
                        <div id="file-list-container" class="file-list-container"></div>
                    </div>
                    <button type="submit" class="btn-primary" id="upload-btn" style="display: none;">
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
                                <?php
                                    $isImage = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $f['name']);
                                ?>
                                    <tr>
                                        <td class="file-name">
                                            <?php if ($isImage): ?>
                                                <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank" title="新しいタブで画像を開く" class="thumbnail-link">
                                                    <img src="<?php echo htmlspecialchars($fileUrl); ?>" class="thumbnail" alt="<?php echo htmlspecialchars($f['name']); ?>">
                                                </a>
                                            <?php else: ?>
                                                <i class="far fa-file"></i>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars($f['name']); ?></span>
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
                                                <form action="" method="post" onsubmit="return handleRename(this, '<?php echo htmlspecialchars($f['name'], ENT_QUOTES); ?>');" style="display:inline;">
                                                    <input type="hidden" name="action" value="rename">
                                                    <input type="hidden" name="filename" value="<?php echo htmlspecialchars($f['name']); ?>">
                                                    <input type="hidden" name="new_filename" class="new-filename-input" value="">
                                                    <button type="submit" class="btn-icon rename-btn" title="名前変更">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </form>
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
        const fileListContainer = document.getElementById('file-list-container');
        const uploadBtn = document.getElementById('upload-btn');
        const toast = document.getElementById('toast');

        let selectedFiles = [];

        dropZone.addEventListener('click', (e) => {
            if (e.target.closest('.remove-file-btn')) return;
            fileInput.click();
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                handleFiles(Array.from(fileInput.files));
                fileInput.value = ''; 
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
                handleFiles(Array.from(e.dataTransfer.files));
            } else {
                const html = e.dataTransfer.getData('text/html');
                const match = html && html.match(/src="([^"]+)"/);
                const url = match ? match[1] : e.dataTransfer.getData('text/uri-list');

                if (url && url.startsWith('http')) {
                    fetchImageAndAddToList(url);
                }
            }
        });

        window.addEventListener('paste', (e) => {
            const items = (e.clipboardData || e.originalEvent.clipboardData).items;
            let pastedFiles = [];
            for (let i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    const blob = items[i].getAsFile();
                    const file = new File([blob], `pasted-image-${Date.now()}-${i}.png`, { type: blob.type });
                    pastedFiles.push(file);
                }
            }
            if (pastedFiles.length > 0) {
                handleFiles(pastedFiles);
            }
        });

        async function fetchImageAndAddToList(url) {
            try {
                const response = await fetch(url);
                const blob = await response.blob();
                let fileName = url.split('/').pop().split('?')[0] || `web-image-${Date.now()}`;
                if (!fileName.includes('.')) {
                    const ext = blob.type.split('/')[1] || 'png';
                    fileName += `.${ext}`;
                }
                const file = new File([blob], fileName, { type: blob.type });
                handleFiles([file]);
            } catch (err) {
                console.error('Error fetching web image:', err);
                alert('Web画像の直接取得に失敗しました。');
            }
        }

        function handleFiles(files) {
            const newFiles = files.filter(file => 
                !selectedFiles.some(s => s.name === file.name && s.size === file.size)
            );
            selectedFiles = [...selectedFiles, ...newFiles];
            renderFileList();
        }

        function renderFileList() {
            if (selectedFiles.length === 0) {
                fileListContainer.style.display = 'none';
                fileListContainer.innerHTML = '';
                uploadBtn.style.display = 'none';
                return;
            }

            fileListContainer.innerHTML = '';
            selectedFiles.forEach((file, index) => {
                const item = document.createElement('div');
                item.className = 'file-info';
                item.innerHTML = `
                    <span>選択中: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)</span>
                    <button type="button" class="remove-file-btn" title="削除">
                        <i class="fas fa-times-circle"></i>
                    </button>
                `;
                item.querySelector('.remove-file-btn').onclick = (e) => {
                    e.stopPropagation();
                    selectedFiles.splice(index, 1);
                    renderFileList();
                };
                fileListContainer.appendChild(item);
            });
            
            fileListContainer.style.display = 'flex';
            uploadBtn.style.display = 'flex';
        }

        document.getElementById('upload-form').onsubmit = function(e) {
            if (selectedFiles.length === 0) return false;
            
            const dataTransfer = new DataTransfer();
            selectedFiles.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
            return true;
        };

        document.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const url = btn.getAttribute('data-url');
                copyToClipboard(url);
            });
        });

        function copyToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    showToast();
                }).catch(err => {
                    console.error('Clipboard API error:', err);
                    fallbackCopy(text);
                });
            } else {
                fallbackCopy(text);
            }
        }

        function fallbackCopy(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.left = "-9999px";
            textArea.style.top = "0";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                const successful = document.execCommand('copy');
                if (successful) showToast();
            } catch (err) {
                console.error('Fallback copy error:', err);
            }
            document.body.removeChild(textArea);
        }

        function showToast() {
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
            }, 2000);
        }

        function handleRename(form, oldName) {
            const newName = prompt('新しいファイル名を入力してください:', oldName);
            if (newName !== null && newName.trim() !== '' && newName !== oldName) {
                form.querySelector('.new-filename-input').value = newName.trim();
                return true;
            }
            return false;
        }
    </script>
</body>
</html>
