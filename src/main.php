<?php
if (!empty($_POST['data']) && !empty($_POST['ecc'])) {
    require_once "QRGenerator.php";
    $qr = new QRGenerator();

    $data = $_POST['data'];
    $ecc = $_POST['ecc'];

    $info = [];

    try {
        $info = $qr->generate($data, $ecc);
    } catch (Exception $e) {
        $info = "Parece ser que el texto es demasiado largo para ser codificado en un QR";
    }
}


?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Generador de QR</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/form.css">
</head>

<body>
    <header>
        <h1>Generador de QR</h1>
        <p>Genera un código QR a partir de un texto</p>
        <div class=".checkbox-inline">
            <a href="https://github.com/TeRacksito/QR-Generator">Github</a>
            <a href="/">Volver</a>
        </div>

    </header>
    <main>
        <section>
            <form action="main.php" method="post">
                <fieldset>
                    <textarea name="data" id="data" placeholder="Texto..." required><?php echo isset($_POST['data']) ? htmlspecialchars($_POST['data']) : ''; ?></textarea>
                    <div style="max-width: 600px;">
                        <div class="inline">
                            <span>Nivel de correción de error: </span>
                            <div class="checkbox-inline">
                                <input type="radio" name="ecc" id="ecc_l" value="L" <?php echo (isset($_POST['ecc']) && $_POST['ecc'] == 'L') ? 'checked' : ''; ?>>
                                <label for="ecc_l">Low</label>
                            </div>
                            <div class="checkbox-inline">
                                <input type="radio" name="ecc" id="ecc_m" value="M" <?php echo (isset($_POST['ecc']) && $_POST['ecc'] == 'M') ? 'checked' : ''; ?>>
                                <label for="ecc_m">Medium</label>
                            </div>
                            <div class="checkbox-inline">
                                <input type="radio" name="ecc" id="ecc_q" value="Q" <?php echo (isset($_POST['ecc']) && $_POST['ecc'] == 'Q') ? 'checked' : ''; ?>>
                                <label for="ecc_q">Quartile</label>
                            </div>
                            <div class="checkbox-inline">
                                <input type="radio" name="ecc" id="ecc_h" value="H" <?php echo (isset($_POST['ecc']) && $_POST['ecc'] == 'H') ? 'checked' : ''; ?>>
                                <label for="ecc_h">High</label>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <button type="submit">Generar</button>

            </form>
        </section>
        <section>
            <div>
                <?php
                if (!empty($_POST['data']) && !empty($_POST['ecc'])) {
                    echo "<h2>Información</h2>";

                    if (is_string($info)) {
                        echo "<p>$info</p>";
                    } else {
                        $mask = $info['mask'];
                        $penalty = $info['penalty'];
                        $modules_size = $info['modules_size'];
                        $version = $info['version'];
                        $ecc_level = $info['ecc_level']; ?>
                        <p><strong>Máscara:</strong> <?= $mask; ?></p>
                        <p><strong>Puntuación:</strong> <?= $penalty; ?></p>
                        <p><strong>Tamaño de los módulos:</strong> <?= $modules_size; ?></p>
                        <p><strong>Versión:</strong> <?= $version; ?></p>
                        <p><strong>Nivel de correción de error:</strong> <?= $ecc_level; ?></p>
                <?php }
                }
                ?>
            </div>
            <div id="qr">
                <?php
                if (!empty($_POST['data']) && !empty($_POST['ecc']) && !is_string($info)) {
                    ob_start();
                    imagepng($qr->image);
                    $imageData = ob_get_clean();
                    echo '<img src="data:image/png;base64,' . base64_encode($imageData) . '" alt="QR Code">';
                }
                ?>
            </div>
        </section>
    </main>





</body>

</html>