<?php
namespace Teracksito\QrGenerator;

use Exception;

class VersionManager {
  private static $versions;

  public static function determineVersion($data, $ecc_level) {
    self::$versions ??=  json_decode(file_get_contents(__DIR__ . '/data/qr_levels.json'), true);
    $length = strlen($data) + 2;
    foreach (self::$versions as $version => $ecc_levels) {        
        $schemas = $ecc_levels[$ecc_level];
        $max_codewords = array_reduce($schemas, function ($acc, $schema) {
            return $acc + $schema['data'] * $schema['blocks'];
        }, 0);
        if (($version > 9 ? $length + 1 : $length) <= $max_codewords) {
            return $version;
        }
    }

    throw new Exception('Data is too long to be encoded in any version. Bye');
  }

  public static function getSchema($version, $ecc_level) {
    return self::$versions[$version][$ecc_level];
  }
}