# PlugShip Receiver

A companion WordPress plugin for the [plugship](https://github.com/plugship/plugship) CLI. It adds REST API endpoints to receive and install plugin ZIP files on your WordPress site.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- An Administrator account with an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)

## Installation

1. Download `plugship-receiver.php`
2. Upload it to your site's `wp-content/plugins/` directory
3. Activate **PlugShip Receiver** from the WordPress admin Plugins page

## REST API Endpoints

All endpoints require authentication via Application Passwords and the `install_plugins` capability.

### `GET /wp-json/plugship/v1/status`

Health check endpoint. Returns receiver version and environment info.

**Response:**

```json
{
  "status": "ok",
  "version": "1.0.0",
  "wp": "6.7.1",
  "php": "8.2.0"
}
```

### `POST /wp-json/plugship/v1/deploy`

Accepts a plugin ZIP file upload and installs it. If the plugin already exists, it is replaced.

**Request:**

- Content-Type: `multipart/form-data`
- Field: `plugin` — the ZIP file
- Optional param: `activate` — set to `false` to skip activation

**Response:**

```json
{
  "success": true,
  "plugin": "my-plugin/my-plugin.php",
  "name": "My Plugin",
  "version": "1.0.0",
  "activated": true
}
```

## How It Works

1. Receives the uploaded ZIP file via the REST API
2. Validates the file type (must be a ZIP archive)
3. Uses WordPress's built-in `Plugin_Upgrader` with `overwrite_package => true` to install or update the plugin
4. Optionally activates the plugin after installation
5. Returns the installed plugin info

## Security

- Only users with the `install_plugins` capability (Administrators) can access these endpoints
- Authentication is handled via WordPress Application Passwords
- Uploaded files are validated for correct MIME type before processing

## License

MIT
