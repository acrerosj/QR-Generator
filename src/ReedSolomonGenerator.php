<?php

/**
 * Reed-Solomon error correction encoder
 */
class ReedSolomonQR
{
    private $exp = [];
    private $log = [];
    private $generator = [];


    public function __construct(int $errorCorrectionBytes)
    {
        $this->initializeField(256, 0x011D);
        $this->generator = $this->createGenerator($errorCorrectionBytes);
    }

    /**
     * Initialize the field for Reed-Solomon encoding
     * @param int $fieldSize The size of the field
     * @param int $primitive The primitive polynomial
     */
    private function initializeField(int $fieldSize, int $primitive): void
    {
        $x = 1;
        for ($i = 0; $i < $fieldSize; $i++) {
            $this->exp[$i] = $x;
            $this->log[$x] = $i;
            $x <<= 1;
            if ($x & $fieldSize) {
                $x ^= $primitive;
            }
        }
        for ($i = $fieldSize; $i < $fieldSize * 2; $i++) {
            $this->exp[$i] = $this->exp[$i - $fieldSize];
        }
    }

    /**
     * Create the generator polynomial
     * @param int $degree The degree of the generator polynomial
     * @return array The generator polynomial
     */
    private function createGenerator(int $degree): array
    {
        $generator = [1];
        for ($d = 0; $d < $degree; $d++) {
            $generator = $this->polyMultiply($generator, [1, $this->exp[$d]]);
        }
        return $generator;
    }

    /**
     * Multiply two polynomials
     * @param array $p1 The first polynomial
     * @param array $p2 The second polynomial
     * @return array The result of the multiplication
     */
    private function polyMultiply(array $p1, array $p2): array
    {
        $result = array_fill(0, count($p1) + count($p2) - 1, 0);
        foreach ($p1 as $i => $v1) {
            foreach ($p2 as $j => $v2) {
                $result[$i + $j] ^= $this->exp[($this->log[$v1] + $this->log[$v2]) % 255];
            }
        }
        return $result;
    }

    /**
     * Divide two polynomials
     * @param array $message The message polynomial
     * @param array $generator The generator polynomial
     * @return array The remainder polynomial
     */
    private function polyDivide(array $message, array $generator): array
    {
        $msg = $message;
        for ($i = 0; $i < count($message) - count($generator) + 1; $i++) {
            $coef = $msg[$i];
            if ($coef !== 0) {
                for ($j = 0; $j < count($generator); $j++) {
                    $msg[$i + $j] ^= $this->exp[($this->log[$coef] + $this->log[$generator[$j]]) % 255];
                }
            }
        }
        return array_slice($msg, count($message) - count($generator) + 1);
    }

    /**
     * Encode the data with Reed-Solomon error correction
     * @param array $data The data to encode
     * @return array The remainder polynomial
     */
    public function encode(array $data): array
    {
        $dataPoly = array_merge($data, array_fill(0, count($this->generator) - 1, 0));
        $remainder = $this->polyDivide($dataPoly, $this->generator);
        return $remainder;
    }
}
