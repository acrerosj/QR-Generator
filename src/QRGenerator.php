<?php

require 'ReedSolomonGenerator.php';

error_reporting(E_ERROR | E_PARSE); // Disable warnings

/**
 * QR Code Generator.
 * To generate a QR code, you need to create an instance of the QRGenerator class and call the generate method.
 * Then you can access the image property to get the generated QR code.
 */
class QRGenerator
{
    public $codewords = null;
    private $ecc_level = null;
    private $version = null;

    private $versions = null;
    private $alignment_positions = null;
    private $version_information = null;
    private $format_information = null;

    private $masks;

    public GdImage $image;


    function __construct()
    {
        // QR Levels. Determines de amount of data and error correction codewords, divided in blocks
        $json = file_get_contents('data/qr_levels.json');
        $data = json_decode($json, true);
        $this->versions = $data;

        // QR Alignment patterns. Determines the position of the alignment patterns in function of the QR version
        $json = file_get_contents('data/alignment_locations.json');
        $data = json_decode($json, true);
        $this->alignment_positions = $data;

        // QR Version information. Determines the version information bits in function of the QR version, for version 7 and above
        $json = file_get_contents('data/version_information.json');
        $data = json_decode($json, true);
        $this->version_information = $data;

        // QR Format information. Determines the format information bits in function of the error correction level and mask
        $json = file_get_contents('data/format_information.json');
        $data = json_decode($json, true);
        $this->format_information = $data;

        // QR Masks. Masking function of each Mask pattern.
        $this->masks = [
            function ($i, $j) {
                return ($i + $j) % 2 == 0;
            },
            function ($i, $j) {
                return $i % 2 == 0;
            },
            function ($i, $j) {
                return $j % 3 == 0;
            },
            function ($i, $j) {
                return ($i + $j) % 3 == 0;
            },
            function ($i, $j) {
                return (floor($i / 2) + floor($j / 3)) % 2 == 0;
            },
            function ($i, $j) {
                return ($i * $j) % 2 + ($i * $j) % 3 == 0;
            },
            function ($i, $j) {
                return (($i * $j) % 3 + $i * $j) % 2 == 0;
            },
            function ($i, $j) {
                return (($i + $j) % 3 + $i + $j) % 2 == 0;
            }
        ];
    }

    public function generate(string $data, string $ecc_level, int $scale = 10): array
    {
        $this->ecc_level = $ecc_level;

        // ###### DATA ENCODING ######
        $this->version = $this->determineVersion($data);

        $this->codewords = $this->normalizeCodewords($this->dataToCodewords($data, $this->version));

        $blocks = $this->generateBlocksWithECC();

        // ###### STRUCTURAL MAPPING ######
        $modules_size = $this->determineModulesSize($this->version);
        // structure_map - 1: dark module, 0: light module, d: data module (will be replaced by the data)
        // The structure map is the base map where the fixed patterns are applied.
        // No data is written in this map. But it contains where the data and mask should be applied.
        $structure_map = array_fill(0, $modules_size, array_fill(0, $modules_size, 'd'));
        $this->applyFixedPatterns($structure_map);
        if ($this->version >= 7) {
            $this->applyInformationBits($structure_map);
        }

        // ###### DATA MAPPING ######
        // shadow_map - 1: dark module, 0: light module
        // The shadow map is the map where the data is written.
        // We use a shadow map because, if we apply the data directly to the structure map, we wouldn't know where the data is.
        $shadow_map = array_fill(0, $modules_size, array_fill(0, $modules_size, 0));
        $this->applyData($structure_map, $shadow_map, $blocks);

        list($mask, $penalty, $masked_map) = $this->determineMask($structure_map, $shadow_map);

        // $this->terminalDrawMap($masked_map);
        // printf("Mask: %d\n", $mask);
        // printf("Penalty: %d\n", $penalty);
        // printf("Modules size: %d\n", $modules_size);
        // printf("Version: %d\n", $this->version);
        // printf("ECC Level: %s\n", $this->ecc_level);

        // ###### DRAW AND SAVE QR CODE ######
        $image = $this->createImageFromMatrix($masked_map);
        $resized_image = $this->sharpResizeImage($image, $scale);
        // imagepng($resized_image, $filename); // for saving the image
        $this->image = $resized_image;

        return [
            'mask' => $mask,
            'penalty' => $penalty,
            'modules_size' => $modules_size,
            'version' => $this->version,
            'ecc_level' => $this->ecc_level
        ];
    }

    /**
     * Generate a QR code image from a matrix of modules.
     * @param array $map reference to the modules matrix, where truly values represent black modules and falsy white modules.
     * @return GdImage The generated image.
     */
    function createImageFromMatrix(array &$map): GdImage
    {
        $b = 4; // border
        $height = $width = count($map);

        $image = imagecreatetruecolor($width + $b * 2, $height + $b * 2);

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);

        imagefill($image, 0, 0, $white);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($map[$y][$x] === 'd') {
                    continue;
                }
                $color = $map[$y][$x] ? $black : $white;
                imagesetpixel($image, $x + $b, $y + $b, $color);
            }
        }

        return $image;
    }

    /**
     * Resize an image pixel perfect.
     * This method is useful to resize the QR code image to a bigger size.
     * It avoids the interpolation of the image, keeping the pixels sharp.
     * @param GdImage $source_image The source image to be resized.
     * @param int $scale The scale factor.
     * @return GdImage The resized image.
     */
    function sharpResizeImage($source_image, $scale)
    {
        $src_width = imagesx($source_image);
        $src_height = imagesy($source_image);

        $new_width = $src_width * $scale;
        $new_height = $src_height * $scale;

        $resized_image = imagecreatetruecolor($new_width, $new_height);

        // Resample
        imagecopyresized($resized_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $src_width, $src_height);

        return $resized_image;
    }

    /**
     * Split codewords in groups and blocks, and generate error correction codewords.
     * This method is purely an abstraction layer.
     * @return array of arrays of blocks. Each block is an array of strings representing bits.
     */
    private function generateBlocksWithECC(): array
    {
        $schemas = $this->versions[$this->version][$this->ecc_level];
        $blocks = [];

        $codeword_index = 0;
        foreach ($schemas as $schema) {
            $blocks_num = $schema['blocks'];
            $block_max_length = $schema['data'];
            $nsym = $schema['nsym'];
            $RS = new ReedSolomonQR($nsym);

            for ($block_index = 0; $block_index < $blocks_num; $block_index++) {
                $block = [];
                for ($i = 0; $i < $block_max_length; $i++) {
                    $codeword = $this->codewords[$codeword_index] ?? null;
                    $codeword_index++;

                    if ($codeword === null) {
                        $codeword = str_pad(decbin($this->getNextPad()), 8, '0', STR_PAD_LEFT);
                    }

                    $block[] = $codeword;
                }
                $error_correction_bytes = $RS->encode(array_map('bindec', $block));
                $error_correction_bytes = array_map(fn($x) => str_pad(decbin($x), 8, '0', STR_PAD_LEFT), $error_correction_bytes);

                $blocks[] = ['data' => $block, 'error' => $error_correction_bytes];
            }
        }

        return $blocks;
    }

    /**
     * Determine the best mask pattern for the given map.
     * As determining the best mask implies applying each mask and evaluating the penalty,
     * this method returns the mask that has the lowest penalty. So its not needed to apply the mask again.
     * @param array $structure_map reference to the structure matrix. Where the data modules are marked as 'd'.
     * @param array $shadow_map reference to the shadow matrix. Where data is written.
     * @return array Containing three elements: the mask index, the penalty, and the masked map.
     * @param string $ecc_level The error correction level, optional.
     */
    public function determineMask(array &$structure_map, array &$shadow_map, string $ecc_level = null): array
    {
        if (!isset($ecc_level)) {
            $ecc_level = $this->ecc_level;
        }

        $modules_size = count($structure_map);

        // realization_map - 1: dark module, 0: light module
        // This map combines the structure map and the shadow map.
        $realization_map = array_fill(0, $modules_size, array_fill(0, $modules_size, 0));
        for ($i = 0; $i < $modules_size; $i++) {
            for ($j = 0; $j < $modules_size; $j++) {
                $realization_map[$i][$j] = $structure_map[$i][$j] === 'd' ? $shadow_map[$i][$j] : $structure_map[$i][$j];
            }
        }

        $penalties = [];

        // ###### EVALUATE MASKS ######
        for ($mask = 0; $mask < 8; $mask++) {
            $penalties[$mask] = array_merge([$mask], $this->evaluateMask($structure_map, $realization_map, $mask, $ecc_level));
        }

        // ###### CHOOSE THE BEST MASK ######
        usort($penalties, function ($a, $b) {
            return $a[1] - $b[1];
        });

        return $penalties[0];
    }

    /**
     * Evaluate the penalty of a mask pattern.
     * The penalty is calculated by evaluating the four rules of the QR code.
     * @param array $structure_map reference to the structure matrix. Where the data modules are marked as 'd'.
     * @param array $realization_map reference to the realization matrix. Where data is written.
     * @param int $mask The mask pattern to be applied.
     * @param string $ecc_level The error correction level, optional.
     */
    public function evaluateMask(array &$structure_map, array $realization_map, int $mask, string $ecc_level = null): array
    {
        if (!isset($ecc_level)) {
            $ecc_level = $this->ecc_level;
        }

        // We apply the format bits here because we need to know the mask to be applied.
        $this->applyFormatBits($realization_map, $ecc_level, $mask);

        $this->applyMask($structure_map, $realization_map, $mask);

        $penalty = 0;

        $penalty += $this->evaluatePenalty1($realization_map);
        $penalty += $this->evaluatePenalty2($realization_map);
        $penalty += $this->evaluatePenalty3($realization_map);
        $penalty += $this->evaluatePenalty4($realization_map);

        return [$penalty, $realization_map];
    }

    /**
     * Apply the mask pattern to the realization map.
     * The mask pattern is applied only to the data modules, given by the structure map.
     * @param array $structure_map reference to the structure matrix. Where the data modules are marked as 'd'.
     * @param array $realization_map reference to the realization matrix. Where data is written.
     * @param int $mask The mask pattern to be applied.
     */
    public function applyMask(array &$structure_map, array &$realization_map, int $mask): void
    {
        $modules_size = count($structure_map);

        // Apply the mask to the shadow map
        for ($i = 0; $i < $modules_size; $i++) {
            for ($j = 0; $j < $modules_size; $j++) {
                if ($structure_map[$i][$j] === 'd' && $this->masks[$mask]($i, $j)) {
                    $realization_map[$i][$j] = $realization_map[$i][$j] == 1 ? 0 : 1;
                }
            }
        }
    }

    /**
     * Evaluate the penalty of the first rule of the QR code.
     * How the rule works:
     * - For each row and column, count the number of consecutive modules of the same color.
     * - If there are 5 or more consecutive modules of the same color, add to the penalty the number of modules minus 2.
     * @param array $realization_map reference to the realization matrix. Where data is written.
     * @return int The penalty of the first rule.
     */
    private function evaluatePenalty1(array &$realization_map): int
    {
        $penalty = 0;
        $modules_size = count($realization_map);

        // horizontal and vertical
        for ($swap = 0; $swap < 2; $swap++) {
            for ($i = $swap ? 1 : 0; $i < $modules_size; $i++) {
                $count = 1;
                for ($j = $swap ? 0 : 1; $j < $modules_size; $j++) {
                    $x = $swap ? $j : $i;
                    $y = $swap ? $i : $j;

                    if ($realization_map[$x][$y] === $realization_map[$x][$y - 1]) {
                        $count++;
                    } else {
                        if ($count >= 5) {
                            $penalty += $count - 2;
                        }
                        $count = 1;
                    }
                }
                if ($count >= 5) {
                    $penalty += $count - 2;
                }
            }
        }

        return $penalty;
    }

    /**
     * Evaluate the penalty of the second rule of the QR code.
     * How the rule works:
     * - For each 2x2 area, count the number of 2x2 squares with the same color.
     * - If there are 2x2 squares with the same color, add to the penalty 3.
     * @param array $realization_map reference to the realization matrix. Where data is written.
     * @return int The penalty of the second rule.
     */
    private function evaluatePenalty2(array &$realization_map): int
    {
        $penalty = 0;
        $modules_size = count($realization_map);

        for ($i = 0; $i < $modules_size - 1; $i++) {
            for ($j = 0; $j < $modules_size - 1; $j++) {
                $target_color = $realization_map[$i][$j];

                $up_right = $realization_map[$i][$j + 1];
                $down_left = $realization_map[$i + 1][$j];
                $down_right = $realization_map[$i + 1][$j + 1];

                if ($target_color === $up_right && $up_right === $down_left && $down_left === $down_right) {
                    $penalty += 3;
                }
            }
        }

        return $penalty;
    }

    /**
     * Evaluate the penalty of the third rule of the QR code.
     * How the rule works:
     * - Search for the pattern 00001011101 or 10111010000 in each row and column.
     * - If the pattern is found, add to the penalty 40.
     * @param array $realization_map reference to the realization matrix. Where data is written.
     */
    private function evaluatePenalty3(&$realization_map)
    {
        $penalty = 0;
        $modules_size = count($realization_map);

        for ($i = 0; $i < $modules_size; $i++) {
            $row = '';
            $col = '';
            for ($j = 0; $j < $modules_size; $j++) {
                $row .= $realization_map[$i][$j];
                $col .= $realization_map[$j][$i];
            }

            $row = str_replace('d', '0', $row);
            $col = str_replace('d', '0', $col);

            $row = preg_replace('/(00001011101|10111010000)/', '', $row);
            $col = preg_replace('/(00001011101|10111010000)/', '', $col);

            $row = str_replace('1', '', $row);
            $col = str_replace('1', '', $col);

            $penalty += strlen($row) + strlen($col);
        }

        return $penalty;
    }

    /**
     * Evaluate the penalty of the fourth rule of the QR code.
     * How the rule works:
     * - Calculate the ratio of dark modules to the total number of modules.
     * - Round the ratio to the nearest multiple of 5.
     * - Calculate the penalty as the absolute difference between the rounded ratio and 50, divided by 5, multiplied by 10.
     * @param array $realization_map reference to the realization matrix. Where data is written.
     */
    private function evaluatePenalty4(&$realization_map)
    {
        $penalty = 0;
        $modules_size = count($realization_map);

        $total = $modules_size ** 2;

        $dark = array_reduce($realization_map, function ($acc, $row) {
            return $acc + array_reduce($row, function ($acc, $module) {
                return $acc + ($module === 1 ? 1 : 0);
            }, 0);
        }, 0);

        $ratio = $dark / $total * 100;
        $multiple = ($ratio - ((int) $ratio % 5));
        $penalty = abs(50 - $multiple) / 5 * 10;

        return $penalty;
    }

    /**
     * Apply the format information bits to the modules matrix.
     * Note how the realization/shadow maps didn't used the space needed for the format bits,
     * as we have applied the dummy format bits in the structure map.
     * @param array $realization_map reference to the modules matrix
     * @param string $ecc_level The error correction level
     * @param int $mask The mask pattern
     */
    public function applyFormatBits(array &$realization_map, string $ecc_level, int $mask): void
    {
        $modules_size = count($realization_map);
        $bits = $this->format_information[$ecc_level][$mask];

        $left_to_right_bits = $bits;

        $i = 8;
        for ($j = 0; $j < 8; $j++) {
            if ($j == 6) continue;
            $length = strlen($left_to_right_bits);
            $realization_map[$i][$j] = $left_to_right_bits[0];
            $left_to_right_bits = substr($left_to_right_bits, 1, $length - 1);
        }

        $right_to_left_bits = strrev($left_to_right_bits);

        $i = 8;
        for ($j = $modules_size - 1; $j >= $modules_size - 8; $j--) {
            $length = strlen($right_to_left_bits);
            $realization_map[$i][$j] = $right_to_left_bits[0];
            $right_to_left_bits = substr($right_to_left_bits, 1, $length - 1);
        }

        $down_to_up_bits = $bits;

        $j = 8;
        for ($i = $modules_size - 1; $i >= $modules_size - 7; $i--) {
            $length = strlen($down_to_up_bits);
            $realization_map[$i][$j] = $down_to_up_bits[0];
            $down_to_up_bits = substr($down_to_up_bits, 1, $length - 1);
        }

        $up_to_down_bits = strrev($down_to_up_bits);

        $j = 8;
        for ($i = 0; $i < 9; $i++) {
            if ($i == 6) continue;
            $length = strlen($up_to_down_bits);
            $realization_map[$i][$j] = $up_to_down_bits[0];
            $up_to_down_bits = substr($up_to_down_bits, 1, $length - 1);
        }
    }

    /**
     * Apply the version information bits to the modules matrix.
     * Only versions 7 and above have version information bits.
     * @param array $map reference to the modules matrix
     * @param int $version The QR version, optional.
     */
    public function applyInformationBits(array &$map, int $version = null): void
    {
        if (!isset($version)) {
            $version = $this->version;
        }

        $version_information = strrev($this->version_information[$version]);

        $row_relative = count($map) - 11;
        $col_relative = 0;

        $pointer = 0;

        // Both bottom left and top right
        // Information bits are applied in the 6x3 area
        for ($j = 0; $j < 6; $j++) {
            for ($i = 0; $i < 3; $i++) {
                $row = $row_relative + $i;
                $col = $col_relative + $j;

                $map[$row][$col] = $map[$col][$row] = $version_information[$pointer];

                $pointer++;
            }
        }
    }

    /**
     * Apply the data codewords to the modules matrix.
     * @param array $structure_map reference to the structure matrix. Where the data modules are marked as 'd'.
     * @param array $shadow_map reference to the shadow matrix. Where data is written.
     * @param array $blocks The blocks of data and error correction codewords
     */
    public function applyData(array &$structure_map, array &$shadow_map, array $blocks): void
    {
        $modules_size = count($structure_map);

        $big_col = $modules_size - 1; // Represents two columns
        $row = $modules_size - 1;
        $row_mode = 'up';
        $col = $big_col; // Witch column of the two columns of the big column

        $block_index = 0;
        $block_type = 'data';
        $block_row = 0;
        $block_col = 0;

        while ($big_col >= 0) {

            // Skip timing horizontal line, by specification
            if ($big_col == 6) {
                $big_col--;
            }

            for ($col = $big_col; $col > $big_col - 2; $col--) {

                if ($structure_map[$row][$col] === 'd') {

                    $block = null;
                    for ($i = $block_index; $i < count($blocks); $i++) {
                        $temp = $blocks[$i][$block_type][$block_row];
                        if (isset($temp)) {
                            $block = $temp;
                            $block_index = $i;
                            break;
                        }
                    }

                    if ($block === null && $block_type === 'data') {
                        $block_type = 'error';
                        $block_index = 0;
                        $block_row = 0;
                        $block_col = 0;

                        $block = $blocks[$block_index][$block_type][$block_row];
                    }

                    $shadow_map[$row][$col] = $block[$block_col];
                    $block_col++;

                    if ($block_col >= 8) {
                        $block_col = 0;
                        $block_index++;
                    }

                    if ($block_index >= count($blocks)) {
                        $block_index = 0;
                        $block_row++;
                    }
                }
            }

            // Toggles the row mode and updates the row and big column
            if ($row_mode === 'up') {
                $row--;
                if ($row < 0) {
                    $row = 0;
                    $big_col -= 2;
                    $row_mode = 'down';
                }
            } else {
                $row++;
                if ($row >= $modules_size) {
                    $row = $modules_size - 1;
                    $big_col -= 2;
                    $row_mode = 'up';
                }
            }
        }
    }

    /**
     * Applies the fixed patterns to the modules matrix.
     * For patterns are applied:
     * - Finder patterns
     * - Dummy format bits
     * - Alignment patterns
     * - Timing patterns
     * @param array $map reference to the modules matrix
     * @param int $version The QR version, optional.
     */
    public function applyFixedPatterns(array &$map, int $version = null): void
    {
        if (!isset($version)) {
            $version = $this->version;
        }

        $modules_size = count($map);

        // ###### FINDER PATTERNS ######
        $finder = [
            [1, 1, 1, 1, 1, 1, 1, 0],
            [1, 0, 0, 0, 0, 0, 1, 0],
            [1, 0, 1, 1, 1, 0, 1, 0],
            [1, 0, 1, 1, 1, 0, 1, 0],
            [1, 0, 1, 1, 1, 0, 1, 0],
            [1, 0, 0, 0, 0, 0, 1, 0],
            [1, 1, 1, 1, 1, 1, 1, 0],
            [0, 0, 0, 0, 0, 0, 0, 0]
        ];

        for ($i = 0; $i < 8; $i++) {
            for ($j = 0; $j < 8; $j++) {
                // Note: The finder patterns are applied in the three corners of the matrix
                $map[$i][$j] = $map[$i][$modules_size - 1 - $j] = $map[$modules_size - 1 - $i][$j] = $finder[$i][$j];
            }
        }

        // ###### DUMMY FORMAT BITS ######
        // Bottom left and top right
        for ($i = $modules_size - 1; $i >= $modules_size - 8; $i--) {
            $map[8][$i] = $map[$i][8] = 0;
        }

        $map[$modules_size - 8][8] = 1; // Dark module

        // Top left
        for ($i = 0; $i < 9; $i++) {
            if ($i == 6) continue;
            $map[8][$i] = $map[$i][8] = 0;
        }

        // ###### ALIGNMENT PATTERNS ######
        $alignment_patterns = [
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 1, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 1]
        ];

        // Alignments are applied in multiple positions.
        // But not all of them are valid for all versions. So we have to check.
        $alignment_positions = $this->alignment_positions[$version] ?? [];
        foreach ($alignment_positions as $i) {
            foreach ($alignment_positions as $j) {

                $continue = false;

                // Scan the area where the alignment pattern will be applied.
                for ($x = -2; $x <= 2; $x++) {
                    for ($y = -2; $y <= 2; $y++) {
                        $module = $map[$i + $x][$j + $y];
                        if (!isset($module) || $module !== 'd') {
                            $continue = true;
                            break;
                        }
                    }

                    if ($continue) {
                        break;
                    }
                }

                if ($continue) {
                    continue;
                }

                // Apply the alignment pattern
                for ($x = -2; $x <= 2; $x++) {
                    for ($y = -2; $y <= 2; $y++) {
                        $map[$i + $x][$j + $y] = $alignment_patterns[$x + 2][$y + 2];
                    }
                }
            }
        }

        // ###### TIMING PATTERNS ######
        // Both horizontal and vertical
        for ($i = 0; $i < $modules_size; $i++) {
            if ($map[6][$i] !== 'd') continue;

            $map[6][$i] = $map[$i][6] = $i % 2 == 0 ? 1 : 0;
        }
    }

    /**
     * Determine the QR version that best fits the given data.
     * @param string $data The data that will be encoded
     * @return int The version that best fits the data
     * @throws Exception If the data is too long to be encoded in any version
     */
    public function determineVersion(string $data): int
    {
        $length = strlen($data) + 2;
        foreach ($this->versions as $version => $ecc_levels) {
            $schemas = $ecc_levels[$this->ecc_level];
            $max_codewords = array_reduce($schemas, function ($acc, $schema) {
                return $acc + $schema['data'] * $schema['blocks'];
            }, 0);
            if (($version > 9 ? $length + 1 : $length) <= $max_codewords) {
                return $version;
            }
        }

        throw new Exception('Data is too long to be encoded in any version.');
    }

    /**
     * Determine the size of the modules matrix.
     * Each module is a square that can be black or white.
     * @param int $version The QR version.
     * @return int The size of the modules matrix (both width and height, since it is a square).
     */
    public function determineModulesSize(int $version): int
    {
        return 17 + 4 * $version;
    }

    /**
     * Normalize the codewords to 8 bits.
     * Codewords that may be more or less than 8 bits are normalized or flatted to 8 bits.
     * @param array $codewords The codewords to be normalized. Each codeword is a string of bits.
     * @return array of strings representing bits. Normalized to 8 bits.
     */
    public function normalizeCodewords(array $codewords): array
    {
        $bits = implode($codewords);
        return str_split($bits, 8);
    }

    /**
     * **DEPRECATED**! Instead see the `getNextPad` method.
     * 
     * Padding the codewords with the pad pattern.
     * The pads are used to fill the codewords when the data is not enough.
     * @param array $codewords The codewords to be padded.
     * @param int $length The length to be reached.
     * @return array of strings representing bits.
     */
    private function padding(array $codewords, int $length): array
    {
        $pad1 = 0b11101100;
        $pad2 = 0b00010001;
        $pad = $length - count($codewords);
        for ($i = 0; $i < $pad; $i++) {
            $codewords[] = str_pad(decbin($i % 2 == 0 ? $pad1 : $pad2), 8, '0', STR_PAD_LEFT);
        }
        return $codewords;
    }

    /**
     * Generative function to get the next pad.
     * The pads are used to fill the codewords when the data is not enough.
     * @return int The next pad.
     */
    private function getNextPad(): int
    {
        static $pad1 = 0b11101100;
        static $pad2 = 0b00010001;
        static $state = false;
        $state = !$state;
        return $state ? $pad1 : $pad2;
    }

    /**
     * Convert the data to binary codewords (8 bits).
     * The codewords concatenation represents segment 0 structure.
     * @param string $data The data to be converted.
     * @param int $version The QR version.
     * @return array of strings representing bits.
     */
    public function dataToCodewords(string $data, int $version): array
    {
        $length = strlen($data);
        $codewords = [];

        // ###### SEGMENT 0 HEADER ######
        $codewords[] = str_pad(decbin(4), 4, '0', STR_PAD_LEFT); // 4 bits - Mode indicator, Binary mode hardcoded, 0100
        $codewords[] = str_pad(decbin($length), $version > 9 ? 16 : 8, '0', STR_PAD_LEFT); // 8 or 16 bits - codewords count indicator

        // ###### SEGMENT 0 DATA ######
        for ($i = 0; $i < $length; $i++) {
            $codewords[] = str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        // ###### SEGMENT 0 TERMINATOR ######
        $codewords[] = str_pad(decbin(0), 4, '0', STR_PAD_LEFT); // 4 bits - Terminator, 0000

        return $codewords;
    }

    /**
     * **DEPRECATED**! Do not use.
     * Reassemble the data from the binary codewords.
     * Does not support the segment structure or normalized codewords.
     */
    public function codewordsToData(array $codewords): string
    {
        $data = '';
        foreach ($codewords as $codeword) {
            $data .= chr(bindec($codeword));
        }
        return $data;
    }

    /**
     * Debugging method to draw the modules matrix in the terminal.
     * `##` represents a dark module, `··` represents a light module, and `++` represents a data module.
     * @param array $map reference to the modules matrix
     */
    public function terminalDrawMap(array &$map): void
    {
        print(PHP_EOL);
        $modules_size = count($map);
        for ($i = 0; $i < $modules_size; $i++) {
            for ($j = 0; $j < $modules_size; $j++) {
                if ($map[$i][$j] === 'd') {
                    echo '++';
                } else {
                    echo $map[$i][$j] ? '##' : '··';
                }
            }
            echo PHP_EOL;
        }
    }
}