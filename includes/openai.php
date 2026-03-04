<?php
if ( ! defined('ABSPATH') ) { exit; }

function summarai_get_user_openai_key(): string {
    if ( function_exists('ag_llm_get_api_key') ) {
        return ag_llm_get_api_key();
    }

    return '';
}

function summarai_safe_error_message( string $message ): string {
    if ( $message === '' ) {
        return 'OpenAI request failed.';
    }

    $message = preg_replace('/sk-[A-Za-z0-9\-_]+/i', '[redacted]', $message);
    return $message;
}

function summarai_openai_request( array $payload ): array {
    $api_key = summarai_get_user_openai_key();
    if ( $api_key === '' ) {
        return array(
            'ok' => false,
            'error' => 'OpenAI API key is missing.',
        );
    }

    $response = wp_remote_post(
        'https://api.openai.com/v1/chat/completions',
        array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 45,
            'body'    => wp_json_encode( $payload ),
        )
    );

    if ( is_wp_error( $response ) ) {
        return array(
            'ok' => false,
            'error' => summarai_safe_error_message( $response->get_error_message() ),
        );
    }

    $code = (int) wp_remote_retrieve_response_code( $response );
    $raw  = (string) wp_remote_retrieve_body( $response );

    if ( $code < 200 || $code >= 300 ) {
        $message = 'OpenAI request failed.';
        if ( $code === 401 || $code === 403 ) {
            $message = 'OpenAI authorization failed.';
        } elseif ( $code === 429 ) {
            $message = 'OpenAI rate limit exceeded.';
        }

        return array(
            'ok' => false,
            'error' => summarai_safe_error_message( $message ),
        );
    }

    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) {
        return array(
            'ok' => false,
            'error' => summarai_safe_error_message( 'OpenAI response was invalid.' ),
        );
    }

    return array(
        'ok' => true,
        'data' => $data,
    );
}
