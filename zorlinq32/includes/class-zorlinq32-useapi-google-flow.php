<?php
/** UseAPI.net Google Flow REST client. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Zorlinq32_UseAPI_Google_Flow {
    const OPTION_CONFIG = 'zorlinq32_useapi_google_flow_config';
    const IMAGES_ENDPOINT = 'https://api.useapi.net/v1/google-flow/images';
    private static $instance = null;
    public static function instance() { return self::$instance ?: self::$instance = new self(); }
    private function __construct() { add_action( 'wp_ajax_zorlinq32_useapi_flow_save_config', [ $this, 'ajax_save_config' ] ); }
    public static function get_config() {
        $value = get_option( self::OPTION_CONFIG, [] );
        $value = is_array( $value ) ? $value : [];
        return [
            'api_token' => isset( $value['api_token'] ) ? (string) $value['api_token'] : '',
            'email'     => isset( $value['email'] ) ? (string) $value['email'] : '',
            'model'     => isset( $value['model'] ) ? (string) $value['model'] : 'nano-banana-2-lite',
        ];
    }
    public static function is_configured() { return '' !== trim( self::get_config()['api_token'] ); }
    public function ajax_save_config() {
        check_ajax_referer( 'zorlinq32_ai_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => '권한 없음' ] );
        $old = self::get_config();
        $token = isset( $_POST['api_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) ) : '';
        $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
        $model = isset( $_POST['model'] ) ? sanitize_key( wp_unslash( $_POST['model'] ) ) : 'nano-banana-2-lite';
        if ( ! in_array( $model, [ 'nano-banana-2-lite', 'nano-banana-2', 'nano-banana-pro' ], true ) ) $model = 'nano-banana-2-lite';
        if ( '' === $token ) $token = $old['api_token'];
        if ( '' === $token ) wp_send_json_error( [ 'message' => 'UseAPI API 토큰을 입력하세요.' ] );
        update_option( self::OPTION_CONFIG, [ 'api_token' => $token, 'email' => $email, 'model' => $model ], false );
        wp_send_json_success( [ 'message' => 'UseAPI Google Flow 설정이 저장되었습니다.' ] );
    }
    public static function generate_image( $prompt, $model = '', $aspect_ratio = '16:9' ) {
        $config = self::get_config();
        if ( '' === trim( $config['api_token'] ) ) return new WP_Error( 'useapi_not_configured', 'UseAPI API 토큰이 설정되지 않았습니다.' );
        if ( '' === $model ) $model = $config['model'];
        if ( ! in_array( $model, [ 'nano-banana-2-lite', 'nano-banana-2', 'nano-banana-pro' ], true ) ) $model = 'nano-banana-2-lite';
        $body = [ 'prompt' => $prompt, 'model' => $model, 'aspectRatio' => $aspect_ratio, 'count' => 1 ];
        if ( '' !== $config['email'] ) $body['email'] = $config['email'];
        $response = wp_remote_post( self::IMAGES_ENDPOINT, [ 'timeout' => 75, 'headers' => [ 'Authorization' => 'Bearer ' . $config['api_token'], 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $body ) ] );
        if ( is_wp_error( $response ) ) return new WP_Error( 'useapi_request_failed', $response->get_error_message() );
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $code || ! is_array( $data ) ) return new WP_Error( 'useapi_http_' . $code, is_array( $data ) && ! empty( $data['error'] ) ? sanitize_text_field( $data['error'] ) : 'UseAPI 이미지 생성 요청이 실패했습니다.' );
        $generated = $data['media'][0]['image']['generatedImage'] ?? $data['image']['generatedImage'] ?? [];
        if ( ! empty( $generated['encodedImage'] ) ) return [ 'body' => base64_decode( $generated['encodedImage'] ), 'mime' => 'image/png', 'model' => $model ];
        if ( empty( $generated['fifeUrl'] ) ) return new WP_Error( 'useapi_missing_image', 'UseAPI 응답에 생성 이미지가 없습니다.' );
        $image = wp_remote_get( esc_url_raw( $generated['fifeUrl'] ), [ 'timeout' => 60 ] );
        if ( is_wp_error( $image ) || 200 !== wp_remote_retrieve_response_code( $image ) ) return new WP_Error( 'useapi_download_failed', 'UseAPI 생성 이미지를 다운로드하지 못했습니다.' );
        return [ 'body' => wp_remote_retrieve_body( $image ), 'mime' => wp_remote_retrieve_header( $image, 'content-type' ) ?: 'image/png', 'model' => $model ];
    }
}
