# CloudDrop ☁️

CloudDrop は、PHP で構築されたシンプルでモダンなファイルアップロード＆共有ツールです。

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/php-%3E%3D8.0-777bb4.svg)

## ✨ 特徴

- **モダンな UI**: Tailwind CSS 風のクリーンで直感的なデザイン。
- **ドラッグ＆ドロップ**: 直感的な操作でファイルをアップロード。
- **ファイル管理**: アップロード済みファイルの一覧表示、ダウンロード、削除。
- **リンク共有**: ワンクリックでファイルへの直リンクをコピー。
- **レスポンシブ**: スマートフォンやタブレットからも快適に利用可能。
- **大容量対応**: サーバー設定により大容量ファイルのアップロードもサポート。

## 🚀 セットアップ

### 必要条件

- PHP 8.0 以上
- Nginx または Apache
- 書き込み権限のあるディレクトリ（`uploads/`）

### インストール

1. リポジトリをクローンまたはダウンロードします。
   ```bash
   git clone https://github.com/n-nagmn/CloudDrop.git
   ```

2. 公開ディレクトリに配置します（例: `/var/www/html/uploader`）。

3. `uploads/` ディレクトリの権限を設定します。
   ```bash
   mkdir -p uploads
   sudo chown -R www-data:www-data uploads
   sudo chmod -R 775 uploads
   ```

### サーバー設定 (Nginx)

大容量ファイルをアップロードする場合は、以下の設定を調整してください。

**Nginx (`/etc/nginx/nginx.conf`):**
```nginx
http {
    client_max_body_size 100M;
}
```

**PHP (`php.ini`):**
```ini
upload_max_filesize = 100M
post_max_size = 100M
```

## 🛠 使用技術

- **Backend**: PHP
- **Frontend**: HTML5, Vanilla CSS, JavaScript
- **Icons**: Font Awesome 6
- **Fonts**: Inter

## 📝 ライセンス

このプロジェクトは [MIT ライセンス](LICENSE) の下で公開されています。
