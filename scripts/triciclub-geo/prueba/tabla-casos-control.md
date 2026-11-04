### «Dónde estoy» (punto del GPS → «X e/ Y y Z»)

| grupo | puntos | antes | después |
|---|---|---|---|
| automático | 64 | 2/64 (3 %) | 64/64 (100 %) |
| zona Alamar | 8 | 0/8 (0 %) | 8/8 (100 %) |
| zona Centro Habana | 8 | 0/8 (0 %) | 8/8 (100 %) |
| zona Habana Vieja | 8 | 0/8 (0 %) | 8/8 (100 %) |
| zona Lawton | 8 | 0/8 (0 %) | 8/8 (100 %) |
| zona Marianao | 8 | 0/8 (0 %) | 8/8 (100 %) |
| zona Santos Suárez | 8 | 1/8 (12 %) | 8/8 (100 %) |
| zona Vedado | 8 | 0/8 (0 %) | 8/8 (100 %) |
| zona Víbora | 8 | 1/8 (12 %) | 8/8 (100 %) |
| **total** | 64 | **2/64 (3 %)** | **64/64 (100 %)** |

### Dirección escrita → punto (`localizar`, la que sale primera en las sugerencias)

| caso | antes | después |
|---|---|---|
| sin GPS | 0/0 (0 %) | 0/0 (0 %) |
| con GPS de la zona | 0/0 (0 %) | 0/0 (0 %) |

### Lugares al escribir (¿sale en las 8 sugerencias?)

| escribe | tiene que salir | antes | después (puesto) |
|---|---|---|---|

### Tiempo en PHP (este VPS, PHP 8.3, sin caché; incluye cargar los JSON en cada búsqueda)

| | antes mediana / máx. | después mediana / máx. |
|---|---|---|
| dónde estoy (sin contar la llamada a OSRM) | 10 / 18 ms | 42 / 69 ms |
| cargar habana-calles.json | 16 ms | 20 ms |
| memoria pico | 10 MB | 24 MB |

### Detalle «dónde estoy»

| grupo | zona | esperado | antes | después |
|---|---|---|---|---|
| automático | Víbora | Milagros e/ figueroa y dstrampes | ❌ Milagros esq. Figueroa | ✅ Milagros e/ D'Strampes y Figueroa |
| automático | Víbora | San Miguel e/ figueroa y jorge | ❌ San Miguel esq. Privada | ✅ San Miguel e/ Jorge y Figueroa |
| automático | Víbora | Avenida Santa Catalina e/ felipe poey y parraga | ❌ Avenida Santa Catalina e/ Parraga y Heredia | ✅ Avenida Santa Catalina e/ Párraga y Felipe Poey |
| automático | Víbora | 1ra e/ acosta y lagueruela/pedro consuegra este | ❌ 1ra e/ Patrocinio y Calle 2 | ✅ 1ra e/ Pedro Consuegra Este y Avenida de Acosta |
| automático | Víbora | San Anastasio e/ vista alegre este y san mariano este | ✅ San Anastasio e/ Vista Alegre Este y San Mariano Este | ✅ San Anastasio e/ Vista Alegre Este y San Mariano Este |
| automático | Víbora | Heredia e/ san mariano y vista alegre | ❌ Heredia e/ Santa Catalina y Carmen | ✅ Heredia e/ Vista Alegre y San Mariano |
| automático | Víbora | Gelabert e/ benito lagueruela y san miguel | ❌ Gelabert | ✅ Gelabert e/ Calzada de San Miguel y Benito Lagueruela |
| automático | Víbora | José de La Luz y Caballero e/ ofarril y acosta | ❌ José de La Luz y Caballero cerca de Acosta | ✅ José de La Luz y Caballero e/ O'Farril y Avenida de Acosta |
| automático | Lawton | Concepción e/ linea y 16 | ❌ Concepción esq. Linea | ✅ Concepción e/ Línea y 16 |
| automático | Lawton | Concepción e/ 13 y 12 | ❌ Concepción esq. Calle 12 | ✅ Concepción e/ 12 y 13 |
| automático | Lawton | Tejar e/ 19 y 20 | ❌ Tejar esq. Calle C | ✅ Tejar e/ 19 y 20 |
| automático | Lawton | Pocito e/ 14 y 15 | ❌ Pocito e/ Calle 15 y Tejar | ✅ Pocito e/ 14 y 15 |
| automático | Lawton | 14 e/ a y b | ❌ 14 e/ Pocito y Pasaje A | ✅ 14 e/ A y B |
| automático | Lawton | E e/ 9 y 10 | ❌ E e/ Calle 10 y Morua Delgado | ✅ E e/ 9na y 10 |
| automático | Lawton | Milagros Este e/ porvenir y armas | ❌ Milagros Este e/ Armas y San Francisco | ✅ Milagros Este e/ Armas y Avenida Porvenir |
| automático | Lawton | D e/ 12 y 13 | ❌ D cerca de Pasaje 5 | ✅ D e/ 12 y 13 |
| automático | Marianao | Avenida 39 e/ 120 y 118 | ❌ Avenida 39 e/ Calle 120 A y Calle 118 A | ✅ Avenida 39 e/ 118 y 120 |
| automático | Marianao | 122A e/ 31 y 29 | ❌ 122A e/ Calle 29 y Calle 35 | ✅ 122A e/ 29 y 31 |
| automático | Marianao | 120 e/ 27 a y 27 b | ❌ 120 cerca de Calle 118 | ✅ 120 e/ 27A y 27B |
| automático | Marianao | 114 e/ 49 y 51 | ❌ 114 cerca de Calle 43 | ✅ 114 e/ 49 y 51 |
| automático | Marianao | 130 e/ 31 y 33 | ❌ 130 e/ Calle 35 y Calle 27 B | ✅ 130 e/ 31 y 33 |
| automático | Marianao | Avenida 43 e/ 112 y 110 | ❌ Avenida 43 e/ Calle 39 A y Calle 108 | ✅ Avenida 43 e/ 110 y 112 |
| automático | Marianao | 102 e/ 41 y 39 | ❌ 102 | ✅ 102 e/ 39 y 41 |
| automático | Marianao | 114 e/ 31 y 35 | ❌ 114 cerca de Calle 112 A | ✅ 114 e/ 31 y 35 |
| automático | Alamar | 5ta B e/ 166 a y 168 | ❌ 5ta B esq. Calle 168 | ✅ 5ta B e/ 166A y 168 |
| automático | Alamar | 3 B e/ 162 y 162 | ❌ 3 B | ✅ 3 B e/ 162 y 162 |
| automático | Alamar | Avenida de Los Cocos e/ 5/alta norte y 5 e | ❌ Avenida de Los Cocos (164) cerca de Calle 5 A | ✅ Avenida de Los Cocos e/ 5ta y 5ta E |
| automático | Alamar | 5ta E e/ 162 d y 164/cocos | ❌ 5ta E | ✅ 5ta E e/ 162 D y Avenida de Los Cocos |
| automático | Alamar | 162 e/ 5 a y 5 | ❌ 162 cerca de Calle 3 J | ✅ 162 e/ 5ta y 5ta A |
| automático | Alamar | 168 e/ 3 y 1 | ❌ 168 cerca de Calle 3 H | ✅ 168 e/ 1ra y 3ra |
| automático | Alamar | 162D e/ 5 g y 5 h | ❌ 162D cerca de Calle 5 E | ✅ 162D e/ 5ta G y 5ta H |
| automático | Alamar | 168 e/ 3 a y 3 h | ❌ 168 e/ Calle 3 H y Calle 1 D | ✅ 168 e/ 3ra A y 3ra H |
| automático | Santos Suárez | Zapote e/ san julio y durege | ❌ Zapote e/ Durege y Gomez | ✅ Zapote e/ Durege y San Julio |
| automático | Santos Suárez | Calle Villanueva e/ herrera y santa felicia | ❌ Calle Villanueva esq. Rodriguez | ✅ Calle Villanueva e/ Calle Santa Felicia y Calle Herrera |
| automático | Santos Suárez | Estrada Palma e/ mayia rodriguez y goicuria | ❌ Estrada Palma | ✅ Estrada Palma e/ Goicuría y Mayía Rodríguez |
| automático | Santos Suárez | Goicuría e/ san mariano y santa catalina | ✅ Goicuría e/ Santa Catalina y San Mariano | ✅ Goicuría e/ San Mariano y Avenida Santa Catalina |
| automático | Santos Suárez | Calzada del 10 de Octubre e/ quiroga y correa/mangos | ❌ Calzada del 10 de Octubre cerca de Cocos | ✅ Calzada del 10 de Octubre e/ Correa y Calle Quiroga |
| automático | Santos Suárez | Heredia e/ alcalde ofarrill/general lee/pasaje general lee y general lacret | ❌ Heredia e/ Cocos y Morell | ✅ Heredia e/ Alcalde O'Farrill y General Lacret |
| automático | Santos Suárez | San Nicolás e/ luyano y marques torre | ❌ San Nicolás e/ Ensenada y Marques Torre | ✅ San Nicolás e/ Calzada de Luyanó y Marqués de la Torre |
| automático | Santos Suárez | Marqués de la Torre e/ madrid y pamplona | ❌ Marqués de la Torre e/ Luyano y San Nicolas | ✅ Marqués de la Torre e/ Pamplona y Madrid |
| automático | Vedado | Calle 5ta e/ 10 y 8 | ❌ Calle 5ta | ✅ Calle 5ta e/ 8 y 10 |
| automático | Vedado | Calle 9 e/ k y j | ❌ Calle 9 cerca de Calle H | ✅ Calle 9 e/ J y K |
| automático | Vedado | Calle 13 e/ 2 y paseo | ❌ Calle 13 esq. Calle 11 | ✅ Calle 13 e/ Paseo y 2da |
| automático | Vedado | Calle D e/ 9/linea y 7/calzada | ❌ Calle D cerca de Linea | ✅ Calle D e/ Línea y Calzada |
| automático | Vedado | Calle 9 e/ j y i | ❌ Calle 9 cerca de Calle H | ✅ Calle 9 e/ I y J |
| automático | Vedado | Calle 17 e/ g/presidentes y h | ❌ Calle 17 cerca de Calle I | ✅ Calle 17 e/ H y Avenida de los Presidentes |
| automático | Vedado | Calle 23 e/ paseo y a | ❌ Calle 23 cerca de Calle A | ✅ Calle 23 e/ A y Paseo |
| automático | Vedado | Calle 23 e/ i y j | ❌ Calle 23 e/ Calle I y Rampa | ✅ Calle 23 e/ I y J |
| automático | Centro Habana | Concordia e/ aramburu y soledad | ❌ Concordia cerca de Marquez Gonzalez | ✅ Concordia e/ Aramburu y Soledad |
| automático | Centro Habana | Oquendo e/ neptuno y san miguel | ❌ Oquendo cerca de Virtudes | ✅ Oquendo e/ Calzada de San Miguel y Neptuno |
| automático | Centro Habana | Padre Varela e/ animas y virtudes | ❌ Padre Varela (Belascoaín) | ✅ Padre Varela e/ Ánimas y Virtudes |
| automático | Centro Habana | Soledad e/ jesus peregrino y salud | ❌ Soledad esq. Jesus Peregrino | ✅ Soledad e/ Jesús Peregrino y Salud |
| automático | Centro Habana | Boulevard de San Rafael e/ amistad y industria | ❌ San Miguel e/ Boulevard San Rafael y Paseo Marti | ✅ Boulevard de San Rafael e/ Amistad y Industria |
| automático | Centro Habana | Avenida Simón Bolívar e/ san nicolas y manrique | ❌ Avenida Simón Bolívar (Reina) cerca de Italia | ✅ Avenida Simón Bolívar e/ Manrique y San Nicolás |
| automático | Centro Habana | Salud e/ gervasio y chavez | ❌ Salud esq. Jose San Martin | ✅ Salud e/ Gervasio y Chávez |
| automático | Centro Habana | San Nicolás e/ salud y reina/simon bolivar | ❌ San Nicolás | ✅ San Nicolás e/ Salud y Avenida Simón Bolívar |
| automático | Habana Vieja | Paseo de Martí e/ jose san martin/san jose y brasil/teniente rey | ❌ Paseo de Martí (Paseo del Prado) e/ Lamparilla y Industria | ✅ Paseo de Martí e/ José de San Martín y Brasil |
| automático | Habana Vieja | Muralla e/ cuba y aguiar | ❌ Muralla cerca de San Ignacio | ✅ Muralla e/ Aguiar y Cuba |
| automático | Habana Vieja | Dragones e/ belgica/monserrate/muralla y agramonte/zulueta | ❌ Dragones e/ Agramonte y Cristo | ✅ Dragones e/ Avenida de Bélgica y Agramonte |
| automático | Habana Vieja | Cuba e/ cuarteles y chacon | ❌ Cuba e/ Pena Pobre y Callejon Chorro | ✅ Cuba e/ Cuarteles y Chacón |
| automático | Habana Vieja | Sol e/ aguiar y cuba | ❌ Sol esq. Muralla | ✅ Sol e/ Aguiar y Cuba |
| automático | Habana Vieja | Habana e/ sol y muralla | ❌ Habana esq. Brasil | ✅ Habana e/ Muralla y Sol |
| automático | Habana Vieja | Chacón e/ habana y aguiar | ❌ Chacón cerca de Compostela | ✅ Chacón e/ Aguiar y Habana |
| automático | Habana Vieja | O'Reilly e/ san ignacio y cuba | ❌ O'Reilly e/ San Ignacio y Aguiar | ✅ O'Reilly e/ San Ignacio y Cuba |

### Detalle dirección escrita

| caso | antes | después |
|---|---|---|
