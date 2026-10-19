<?php
date_default_timezone_set('Asia/Tokyo');
session_start();

// サブパスでも安全に動作するリダイレクト先を算出
$script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));

// CSRFトークンの発行
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
// CSRF検証
function check_csrf() {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('CSRF');
    }
}

// バイト数を可読な文字列に変換
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// PHPのアップロード上限設定を検査（アプリ側は10MBを想定）
function parseSizeToBytes($sizeStr) {
    $sizeStr = trim((string)$sizeStr);
    if ($sizeStr === '') return 0;
    $last = strtolower($sizeStr[strlen($sizeStr) - 1]);
    $num = (float)$sizeStr;
    switch ($last) {
        case 'g': $num *= 1024 * 1024 * 1024; break;
        case 'm': $num *= 1024 * 1024; break;
        case 'k': $num *= 1024; break;
    }
    return (int)$num;
}
$phpMaxBytes = parseSizeToBytes(ini_get('upload_max_filesize'));
$appMaxBytes = 10 * 1024 * 1024;
$sizeMismatchWarning = $phpMaxBytes < $appMaxBytes
    ? "注意: サーバーの upload_max_filesize（" . ini_get('upload_max_filesize') . "）がアプリの上限（10MB）より小さいため、10MBを超えるファイルはアップロードできません。"
    : "";

$uploadDir = "uploads/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$message = isset($_SESSION['message']) ? $_SESSION['message'] : "";
$status = isset($_SESSION['status']) ? $_SESSION['status'] : "";
unset($_SESSION['message'], $_SESSION['status']);

// Windowsの予約名チェック（CON, PRN, AUX, NUL, COM1-9, LPT1-9）
function isWindowsReservedName($name) {
    return (bool)preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', $name);
}

// ファイル名の検証（許可文字 + Windows予約名）。OKならnull、NGならエラーメッセージを返す
function validateFilename($name) {
    if ($name === "") {
        return "ファイル名が空です。";
    }
    if (strpos($name, ':') !== false) {
        return "ファイル名にコロン（:）は使えません。";
    }
    if (strpos($name, '.') === 0) {
        return "ドットで始まるファイル名は使用できません。";
    }
    if (strlen($name) > 255) {
        return "ファイル名が長すぎます（255バイトまで）。";
    }
    if (!preg_match('/^[\w.\-\s\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{FF66}-\x{FFDC}]+$/u', $name)) {
        return "ファイル名に使用できない文字が含まれています。";
    }
    $stem = pathinfo($name, PATHINFO_FILENAME);
    if (isWindowsReservedName($stem)) {
        return "「" . $stem . "」はWindowsの予約名のため使用できません。";
    }
    return null;
}

// post_max_size超過の検出（CSRFトークンすらPOSTに届いていなければ、POST全体がpost_max_size超過と推定）
if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($_FILES) && !isset($_POST['csrf_token'])) {
    $_SESSION['message'] = "アップロードに失敗しました。POSTデータのサイズが post_max_size を超えている可能性があります。";
    $_SESSION['status'] = "error";
}

// ファイル削除処理
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "delete") {
    check_csrf();
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
    } else {
        $_SESSION['message'] = "ファイルは既に削除されていました。";
        $_SESSION['status'] = "error";
    }
    header('Location: ' . $script . '/index.php');
    exit;
}

// ファイル名前変更処理
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "rename") {
    check_csrf();
    $filename = basename($_POST["filename"]);
    $rawNewName = $_POST["new_filename"];
    $newName = basename((string)$rawNewName);
    $newName = trim($newName);
    $newName = rtrim($newName, " ."); // 末尾のスペースやドットを除去

    $oldExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if ($newName !== "") {
        // 拡張子は元のファイルと同一に強制
        $stem = rtrim($newName, "."); // 末尾ドットを除去してから拡張子を取り直す（二重ドットの防止）
        $newExt = strtolower(pathinfo($stem, PATHINFO_EXTENSION));
        if ($newExt !== "" && $newExt !== $oldExt) {
            $newName = preg_replace('/\.[^\.]*$/', '', $stem) . '.' . $oldExt;
        } elseif ($newExt === "" && $oldExt !== "") {
            $newName = $stem . '.' . $oldExt;
        } else {
            $newName = $stem;
        }

        // クリーンアップ後にファイル名が空になった場合はエラーを返す（サイレントリダイレクトの防止）
        if ($newName === "") {
            $_SESSION['message'] = "ファイル名が空です。";
            $_SESSION['status'] = "error";
        } else {
            // 不正な文字・Windows基底名の癖のチェック（アップロードと同じ検証）
            $invalidReason = validateFilename($newName);
            if ($invalidReason !== null) {
                $_SESSION['message'] = $invalidReason;
                $_SESSION['status'] = "error";
            } else {
            $oldFilePath = $uploadDir . $filename;
            $newFilePath = $uploadDir . $newName;

            // rename()の戻り値を信頼し、失敗時は結果を検証して適切なエラーを返す
            if (file_exists($oldFilePath)) {
                if (rename($oldFilePath, $newFilePath) && is_file($newFilePath)) {
                    $_SESSION['message'] = "ファイル名を「" . htmlspecialchars($newName) . "」に変更しました。";
                    $_SESSION['status'] = "success";
                } else {
                    // TOCTOUレース: 競合で同名ファイルが作成された、または権限エラー
                    $_SESSION['message'] = "ファイル名の変更に失敗しました（同じ名前のファイルが既に存在するか、サーバーの権限により変更できません）。";
                    $_SESSION['status'] = "error";
                }
            } else {
                $_SESSION['message'] = "ファイルが存在しないか、同じ名前のファイルが既に存在します。";
                $_SESSION['status'] = "error";
            }
            }
        }
    } else {
        // クリーンアップ後にファイル名が空になった場合（例: 末尾ドット/スペースのみ）
        $_SESSION['message'] = "ファイル名が空です。末尾のスペースやドットは自動で除去されますが、除去後に何も残らないため名前を変更できません。";
        $_SESSION['status'] = "error";
    }
    header('Location: ' . $script . '/index.php');
    exit;
}

// ファイルアップロード処理（複数対応）
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["files"])) {
    check_csrf();
    $files = $_FILES["files"];
    $uploadedCount = 0;
    $errors = 0;
    $errorList = [];
    $renamedNames = [];

    // $_FILESのサブ配列（name, type, size, tmp_name, error）が等長の配列であることを検証
    $fileKeys = ["name", "type", "size", "tmp_name", "error"];
    $expectedCount = null;
    $filesMalformed = false;
    foreach ($fileKeys as $key) {
        if (!isset($files[$key]) || !is_array($files[$key])) {
            $filesMalformed = true;
            break;
        }
        if ($expectedCount === null) {
            $expectedCount = count($files[$key]);
        } elseif (count($files[$key]) !== $expectedCount) {
            $filesMalformed = true;
            break;
        }
    }
    if ($filesMalformed) {
        $_SESSION['message'] = "アップロードに失敗しました（不正なリクエスト形式です）。";
        $_SESSION['status'] = "error";
        header('Location: ' . $script . '/index.php');
        exit;
    }

    if (isset($files["name"]) && is_array($files["name"])) {
        $fileCount = count($files["name"]);
        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxBytes = $appMaxBytes;
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files["error"][$i] === UPLOAD_ERR_OK) {
                $origName = basename($files["name"][$i]);
                $origName = trim($origName);
                $origName = rtrim($origName, " ."); // 末尾のスペースやドットを除去
                // ファイル名検証（許可文字 + Windows予約名）
                $invalidName = validateFilename($origName);
                if ($invalidName !== null) {
                    $errors++;
                    $errorList[] = "「" . $files["name"][$i] . "」: " . $invalidName;
                    continue;
                }
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExts, true)) {
                    $errors++;
                    $errorList[] = "「" . $origName . "」: 許可されていないファイル形式です。";
                    continue;
                }
                if ($files["size"][$i] > $maxBytes) {
                    $errors++;
                    $errorList[] = "「" . $origName . "」: サイズが大きすぎます（" . formatBytes($maxBytes) . "まで）。";
                    continue;
                }
                // MIMEタイプをfinfoで検証（finfoの利用ができない場合はgetimagesizeのみの補足チェックにフォールバック）
                if ($finfo) {
                    $mime = finfo_file($finfo, $files["tmp_name"][$i]);
                    if ($mime === false || !in_array($mime, $allowedMimeTypes, true)) {
                        $errors++;
                        $errorList[] = "「" . $origName . "」: 実際のファイル内容が許可された画像形式ではありません。";
                        continue;
                    }
                } else {
                    if (@getimagesize($files["tmp_name"][$i]) === false) {
                        $errors++;
                        $errorList[] = "「" . $origName . "」: 破損しているか無効な画像ファイルです。";
                        continue;
                    }
                }
                // 同名ファイルが存在する場合は連番サフィックス（cat-1.jpg, cat-2.jpg）でユニークな名前を生成
                $stem = pathinfo($origName, PATHINFO_FILENAME);
                $ext = pathinfo($origName, PATHINFO_EXTENSION);
                $finalName = $origName;
                if (file_exists($uploadDir . $origName)) {
                    $finalName = null;
                    for ($suffix = 1; $suffix < 10000; $suffix++) {
                        $candidate = $stem . '-' . $suffix . ($ext !== "" ? '.' . $ext : "");
                        if (!file_exists($uploadDir . $candidate)) {
                            $finalName = $candidate;
                            break;
                        }
                    }
                    if ($finalName === null) {
                        $errors++;
                        $errorList[] = "「" . $origName . "」: 保存先の生成に失敗しました。";
                        continue;
                    }
                }
                $targetPath = $uploadDir . $finalName;
                // いったん一時名に書き込み、最終名へアトミックにrenameすることで途中破損したファイルが残らない
                $tempPath = $uploadDir . '.tmp_' . bin2hex(random_bytes(8));
                if (!move_uploaded_file($files["tmp_name"][$i], $tempPath)) {
                    $errors++;
                    $errorList[] = "「" . $origName . "」: サーバーへの保存に失敗しました。";
                    continue;
                }
                if (file_exists($targetPath)) {
                    // TOCTOUレース: 書き込み後に同名ファイルが作成された
                    if (!rename($tempPath, $tempPath . '.conflict')) {
                        @unlink($tempPath);
                    }
                    $errors++;
                    $errorList[] = "「" . $origName . "」: 保存先に競合が発生したため保存に失敗しました。";
                    continue;
                }
                if (rename($tempPath, $targetPath)) {
                    $uploadedCount++;
                    if ($finalName !== $origName) {
                        $renamedNames[] = $origName . ' -> ' . $finalName;
                    }
                } else {
                    @unlink($tempPath);
                    $errors++;
                    $errorList[] = "「" . $origName . "」: サーバーへの保存に失敗しました。";
                }
            } else if ($files["error"][$i] !== UPLOAD_ERR_NO_FILE) {
                $errors++;
                $errorList[] = "「" . ($files["name"][$i] !== "" ? $files["name"][$i] : "無名") . "」: アップロードエラー（コード " . $files["error"][$i] . "）。";
            }
        }
        if ($finfo) finfo_close($finfo);
    }

    if ($uploadedCount > 0) {
        $_SESSION['message'] = $uploadedCount . "件のファイルをアップロードしました。";
        if (!empty($renamedNames)) {
            $_SESSION['message'] .= "<br>（名前が変更されました: " . implode(", ", $renamedNames) . "）";
        }
        if ($errors > 0) $_SESSION['message'] .= "（" . $errors . "件失敗）";
        $_SESSION['status'] = "success";
    } else if ($uploadedCount === 0 && $errors === 0) {
        $_SESSION['message'] = "ファイルが選択されていません。アップロードしたいファイルを選択してください。";
        $_SESSION['status'] = "error";
    } else if ($errors > 0) {
        $_SESSION['message'] = "アップロードに失敗しました。";
        if (!empty($errorList)) {
            $_SESSION['message'] .= "<br>" . implode("</br>", $errorList);
        }
        $_SESSION['status'] = "error";
    }
    header('Location: ' . $script . '/index.php');
    exit;
}

// サーバーのURLベースを取得（ホストヘッダ注入対策としてHTTP_HOSTを検証）
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = filter_var($_SERVER['HTTP_HOST'] ?? '', FILTER_VALIDATE_HOST) ?: 'localhost';
$currentDir = dirname($_SERVER['PHP_SELF']);
$baseUrl = $protocol . "://" . $host . rtrim($currentDir, '/') . "/uploads/";
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CloudDrop - Simple File Sharing</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__ . '/style.css') ?: 0; ?>">
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
                        <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <?php if ($sizeMismatchWarning !== ""): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo $sizeMismatchWarning; ?>
                    </div>
                <?php endif; ?>

                <form action="" method="post" enctype="multipart/form-data" id="upload-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
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
                            $files = array_diff(scandir($uploadDir) ?: [], array(".", ".."));
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
                                                <a href="<?php echo htmlspecialchars($fileUrl); ?>" target="_blank" rel="noopener noreferrer" title="新しいタブで画像を開く" class="thumbnail-link">
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
                                                <a href="uploads/<?php echo rawurlencode($f['name']); ?>" class="btn-icon" target="_blank" rel="noopener noreferrer" title="ダウンロード">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                                <button class="btn-icon copy-btn" data-url="<?php echo htmlspecialchars($fileUrl, ENT_QUOTES); ?>" title="リンクをコピー">
                                                    <i class="fas fa-link"></i>
                                                </button>
                                                <form action="" method="post" onsubmit="return handleRename(this, '<?php echo htmlspecialchars($f['name'], ENT_QUOTES); ?>');" style="display:inline;">
                                                    <input type="hidden" name="action" value="rename">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="filename" value="<?php echo htmlspecialchars($f['name']); ?>">
                                                    <input type="hidden" name="new_filename" class="new-filename-input" value="">
                                                    <button type="submit" class="btn-icon rename-btn" title="名前変更">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </form>
                                                <form action="" method="post" onsubmit="return confirm('本当に削除しますか？');" style="display:inline;">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
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
                    if (!blob) continue;
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
                const nameSpan = document.createElement('span');
                nameSpan.appendChild(document.createTextNode('選択中: ' + file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)'));
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'remove-file-btn';
                removeBtn.title = '削除';
                const removeIcon = document.createElement('i');
                removeIcon.className = 'fas fa-times-circle';
                removeBtn.appendChild(removeIcon);
                item.appendChild(nameSpan);
                item.appendChild(removeBtn);
                removeBtn.onclick = (e) => {
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

            // 送信中はボタンを無効化して読み込み状態を表示
            uploadBtn.disabled = true;
            uploadBtn.dataset.originalHtml = uploadBtn.innerHTML;
            uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> アップロード中...';
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
            let successful = false;
            try {
                successful = document.execCommand('copy');
            } catch (err) {
                console.error('Fallback copy error:', err);
            }
            document.body.removeChild(textArea);
            if (successful) {
                showToast();
            } else {
                showErrorToast();
            }
        }

        function showToast() {
            showToastMessage('コピーしました！');
        }

        function showErrorToast() {
            showToastMessage('コピーに失敗しました。');
        }

        function showToastMessage(msg) {
            const original = toast.dataset.originalText || toast.textContent;
            toast.textContent = msg;
            toast.classList.add('show', 'toast-error');
            setTimeout(() => {
                toast.classList.remove('show', 'toast-error');
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
