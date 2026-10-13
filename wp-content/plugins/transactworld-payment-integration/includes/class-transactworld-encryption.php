<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class TWLD_TransactWorld_Encryption {

	public function encrypt1( $plainText, $key ) {
		$secretKey = $this->hextobin( md5( $key ) );
		$initVector = pack( 'C*', 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f );
		$openMode = mcrypt_module_open( MCRYPT_RIJNDAEL_128, '', 'cbc', '' );
		$blockSize = mcrypt_get_block_size( MCRYPT_RIJNDAEL_128, 'cbc' );
		$plainPad = $this->pkcs5_pad( $plainText, $blockSize );
		if ( mcrypt_generic_init( $openMode, $secretKey, $initVector ) != -1 ) {
			$encryptedText = mcrypt_generic( $openMode, $plainPad );
			mcrypt_generic_deinit( $openMode );
			return bin2hex( $encryptedText );
		}
		return false; // Indicate encryption failure
	}

	public function decrypt1( $encryptedText, $key ) {
        // Decryption logic for mcrypt (PHP <= 7.0) - Not used in this example, but included for completeness.
        $secretKey = $this->hextobin(md5($key));
        $initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
        $encryptedText = $this->hextobin($encryptedText);
        $openMode = mcrypt_module_open(MCRYPT_RIJNDAEL_128, '', 'cbc', '');
        mcrypt_generic_init($openMode, $secretKey, $initVector);
        $decryptedText = mdecrypt_generic($openMode, $encryptedText);
        $decryptedText = rtrim($decryptedText, "\0");
        mcrypt_generic_deinit($openMode);
        return $decryptedText;
	}

	public function encrypt2( $plainText, $key ) {
		$cipher     = "AES-128-CBC";
        $ivlen      = openssl_cipher_iv_length( $cipher );
        $iv         = openssl_random_pseudo_bytes( $ivlen );
        $ciphertext_raw = openssl_encrypt( $plainText, $cipher, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext_raw ) {
            TWLD_TransactWorld_Logger::log( 'OpenSSL Encryption Failed: ' . openssl_error_string() );
            return false; // Indicate encryption failure.
        }
        $hmac       = hash_hmac( 'sha256', $ciphertext_raw, $key, true );
        $ciphertext = base64_encode( $iv . $hmac . $ciphertext_raw );
		return $ciphertext;
	}

	public function decrypt2( $encryptedText, $key ) {
		//decrypt later....
		$cipher     = "AES-128-CBC";
		$c = base64_decode($encryptedText);
		$ivlen = openssl_cipher_iv_length($cipher);
		$iv = substr($c, 0, $ivlen);
		$hmac = substr($c, $ivlen, $sha2len=32);
		$ciphertext_raw = substr($c, $ivlen+$sha2len);
		$original_plaintext = openssl_decrypt($ciphertext_raw, $cipher, $key, $options=OPENSSL_RAW_DATA, $iv);
		$calcmac = hash_hmac('sha256', $ciphertext_raw, $key, $as_binary=true);
        return $original_plaintext; // Return decrypted text
	}

	//*********** Padding Function *********************

	private function pkcs5_pad( $plainText, $blockSize ) {
		$pad = $blockSize - ( strlen( $plainText ) % $blockSize );
		return $plainText . str_repeat( chr( $pad ), $pad );
	}

	//********** Hexadecimal to Binary function for php 4.0 version ********

	private function hextobin( $hexString ) {
		$length = strlen( $hexString );
		$binString = '';
		$count = 0;
		while ( $count < $length ) {
			$subString = substr( $hexString, $count, 2 );
			$packedString = pack( 'H*', $subString );
			if ( $count == 0 ) {
				$binString = $packedString;
			} else {
				$binString .= $packedString;
			}

			$count += 2;
		}
		return $binString;
	}
}