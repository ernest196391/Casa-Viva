<?php
// Reproduce la consulta real de Nadielys sin WordPress, red, clientes ni cambios de tarifas.
define('ABSPATH', __DIR__ . '/');
class CVD_Product_Search {
    public static function normalize(string $s): string {
        return strtolower(strtr($s, ['í'=>'i','é'=>'e','á'=>'a','ó'=>'o','ú'=>'u']));
    }
}
class CVD_Shipping_Rates {
    public static function localities(): array {
        return ['Playa'=>['Playa','Jaimanitas','Miramar'], 'Plaza'=>['El Vedado']];
    }
    public static function quote(string $municipality, string $zone): array {
        return ['status'=>'zone','fee'=>['Playa'=>1800,'Jaimanitas'=>2500,'Miramar'=>2000,'El Vedado'=>1500][$zone]];
    }
}
require __DIR__ . '/../../wordpress/casa-viva-dropship-core/includes/class-cvd-contextual-assistant.php';
$answer = new ReflectionMethod('CVD_Contextual_Assistant','shipping_answer');
$check = static function(string $q, string $expected) use($answer): void {
    $r=$answer->invoke(null,CVD_Product_Search::normalize($q),['ratesUrl'=>'/tarifas-mensajeria/']);
    if(strpos($r['answer'],$expected)===false)throw new RuntimeException($q . ': ' . $r['answer']);
    echo "OK: $q\n";
};
$check('Precio de mensajería hasta playa Jaimanita','Jaimanitas, Playa cuesta 2500');
$check('Mensajería a Playa Jaimanitas','Jaimanitas, Playa cuesta 2500');
$check('Mensajería a Playa','¿En qué reparto de Playa?');
$check('Mensajería a Playa Jaimanito','¿En qué reparto de Playa?');
$check('Mensajería al reparto Playa','Playa, Playa cuesta 1800');
$check('Mensajería zona Playa','Playa, Playa cuesta 1800');
$check('Mensajería a Miramar Playa','Miramar, Playa cuesta 2000');
$check('Mensajería a El Vedado Plaza','El Vedado, Plaza cuesta 1500');
$check('Mensajería a un lugar desconocido','Dime, por ejemplo:');
