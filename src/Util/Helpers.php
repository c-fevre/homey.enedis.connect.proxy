<?php

namespace App\Util;

class Helpers {  
  /*
  // Check if environment variables are defined, or return an error
  $required = ['BASE_URL', 'LIMIT_REQUESTS_PER_MINUTE', 'AUTHORIZATION_ENDPOINT', 'TOKEN_ENDPOINT'];
  $complete = true;
  foreach($required as $r) {
    if(!getenv($r))
      $complete = false;
  }
  if(!$complete) {
    echo "Missing app configuration.\n";
    echo "Please copy .env.example to .env and fill out the variables, or\n";
    echo "define all environment variables accordingly.\n";
    die(1);
  }

  if(getenv('MONGODB_DB')) {
    $result = Cache::connect();
  }
  */

  public static function base64_urlencode($string) {
    return rtrim(strtr(base64_encode($string), '+/', '-_'), '=');
  }

  public static function random_alpha_string($len) {
    // Caractères URL-safe : majuscules + chiffres sauf 0/O/I/1 pour éviter confusion
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $str = '';
    for($i=0; $i<$len; $i++)
      $str .= substr($chars, random_int(0, strlen($chars)-1), 1);
    return $str;
  }
  
  /**
   * Génère un code utilisateur sécurisé avec très haute entropie
   * Format: XXXXXX-XXXXXX-XXXXXX-XXXXXX (24 caractères + 3 tirets)
   * Entropie: ~120 bits (32^24 = 1.2 × 10^36 combinaisons)
   * Temps brute force: ~10^19 ans @ 120M req/sec
   * URL-safe, lisible et impossible à deviner
   */
  public static function generate_secure_user_code() {
    // 24 caractères pour une sécurité maximale
    return self::random_alpha_string(6) . '-' . 
           self::random_alpha_string(6) . '-' . 
           self::random_alpha_string(6) . '-' . 
           self::random_alpha_string(6);
  }
}
