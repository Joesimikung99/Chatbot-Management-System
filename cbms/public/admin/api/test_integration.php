<?php
define('BASE_PATH', dirname(__DIR__, 3));
require_once BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Helpers\Auth;
use GuzzleHttp\Client;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

header('Content-Type: application/json');

try {
    // Only allow logged in users with dashboard permission
    $user = Auth::user();
    if (!$user || !Auth::can('view_dashboard')) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $platform = $_GET['platform'] ?? '';
    $client = new Client(['timeout' => 10]);

    if ($platform === 'line') {
        $token = $_ENV['LINE_CHANNEL_ACCESS_TOKEN'] ?? '';
        if (empty($token)) {
            echo json_encode(['success' => false, 'message' => 'ยังไม่ได้ตั้งค่า Token']);
            exit;
        }

        // Test LINE API
        $response = $client->get('https://api.line.me/v2/bot/info', [
            'headers' => [
                'Authorization' => "Bearer {$token}"
            ],
            'http_errors' => false // don't throw exception on 4xx/5xx
        ]);

        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody(), true);
            echo json_encode([
                'success' => true, 
                'message' => 'เชื่อมต่อแล้ว: ' . ($data['displayName'] ?? 'LINE Bot')
            ]);
        } else {
            $data = json_decode($response->getBody(), true);
            echo json_encode([
                'success' => false, 
                'message' => 'Token ใช้งานไม่ได้ (HTTP ' . $response->getStatusCode() . ')'
            ]);
        }

    } elseif ($platform === 'facebook') {
        $token = $_ENV['FACEBOOK_PAGE_ACCESS_TOKEN'] ?? '';
        if (empty($token)) {
            echo json_encode(['success' => false, 'message' => 'ยังไม่ได้ตั้งค่า Token']);
            exit;
        }

        // Test Facebook API
        $response = $client->get('https://graph.facebook.com/v19.0/me', [
            'query' => [
                'access_token' => $token,
                'fields' => 'name,id'
            ],
            'http_errors' => false
        ]);

        if ($response->getStatusCode() === 200) {
            $data = json_decode($response->getBody(), true);
            echo json_encode([
                'success' => true, 
                'message' => 'เชื่อมต่อแล้ว: ' . ($data['name'] ?? 'Facebook Page')
            ]);
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'Token ไม่ถูกต้อง หรือหมดอายุ'
            ]);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'ไม่รู้จักแพลตฟอร์ม']);
    }

} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'การเชื่อมต่อขัดข้อง: ' . $e->getMessage()]);
}
