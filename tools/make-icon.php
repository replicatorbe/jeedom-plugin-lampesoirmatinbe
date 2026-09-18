<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * L'icône est dessinée ici plutôt que déposée en binaire opaque : quatre
 * couleurs et une ampoule, qu'on peut relire et refaire. Le dessin est fait en
 * 1024 puis réduit en 256, ce qui donne les bords lissés que GD ne produit pas
 * sur un remplissage direct.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$jaune    = imagecolorallocate($grand, 0xF5, 0xB3, 0x00);
$jauneVif = imagecolorallocate($grand, 0xFF, 0xD1, 0x4A);
$gris     = imagecolorallocate($grand, 0x5B, 0x6B, 0x73);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* Les rayons d'abord : le bulbe passera par-dessus leur base. */
$centreX = $e(128);
$centreY = $e(104);
foreach (array(200, 230, 260, 280, 310, 340) as $angle) {
    $radian = deg2rad($angle);
    imagesetthickness($grand, $e(9));
    imageline($grand,
        (int) ($centreX + cos($radian) * $e(80)), (int) ($centreY + sin($radian) * $e(80)),
        (int) ($centreX + cos($radian) * $e(104)), (int) ($centreY + sin($radian) * $e(104)),
        $jauneVif);
}

/* Le bulbe et son col. */
imagefilledellipse($grand, $centreX, $centreY, $e(124), $e(124), $jaune);
imagefilledrectangle($grand, $e(104), $e(150), $e(152), $e(176), $jaune);

/* Le filament, en creux : c'est lui qui fait lire « ampoule » et non « soleil ». */
imagesetthickness($grand, $e(8));
imageline($grand, $e(110), $e(112), $e(128), $e(86), $jauneVif);
imageline($grand, $e(128), $e(86), $e(146), $e(112), $jauneVif);

/* Le culot. */
imagefilledrectangle($grand, $e(104), $e(178), $e(152), $e(196), $gris);
imagefilledrectangle($grand, $e(110), $e(200), $e(146), $e(214), $gris);
imagefilledrectangle($grand, $e(116), $e(218), $e(140), $e(228), $gris);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/lampesoirmatinbe_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
