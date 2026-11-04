### «Dónde estoy» (punto del GPS → «X e/ Y y Z»)

| grupo | puntos | antes | después |
|---|---|---|---|
| a mano | 66 | 4/66 (6 %) | 66/66 (100 %) |
| esquina | 6 | 2/6 (33 %) | 6/6 (100 %) |
| zona Centro Habana | 15 | 0/15 (0 %) | 15/15 (100 %) |
| zona Habana Vieja | 19 | 1/19 (5 %) | 19/19 (100 %) |
| zona Nuevo Vedado | 1 | 0/1 (0 %) | 1/1 (100 %) |
| zona Playa | 7 | 1/7 (14 %) | 7/7 (100 %) |
| zona Vedado | 30 | 4/30 (13 %) | 30/30 (100 %) |
| **total** | 72 | **6/72 (8 %)** | **72/72 (100 %)** |

### Dirección escrita → punto (`localizar`, la que sale primera en las sugerencias)

| caso | antes | después |
|---|---|---|
| sin GPS | 31/39 (79 %) | 34/39 (87 %) |
| con GPS de la zona | 31/39 (79 %) | 39/39 (100 %) |

### Lugares al escribir (¿sale en las 8 sugerencias?)

| escribe | tiene que salir | antes | después (puesto) |
|---|---|---|---|
| «Vari» | Variedades Obispo | ❌ | ✅ 1.º — 1.ª: Variedades Obispo · Obispo e/ Habana y Compostela, La Habana Vieja |
| «Vari» (GPS) | Variedades Obispo | ❌ | ✅ 1.º — 1.ª: Variedades Obispo · Obispo e/ Habana y Compostela, La Habana Vieja |
| «Copp» | Coppelia | ❌ | ✅ 1.º — 1.ª: Coppelia · Avenida 21 e/ K y L, Plaza de la Revolución |
| «Copp» (GPS) | Coppelia | ❌ | ✅ 1.º — 1.ª: Coppelia · Avenida 21 e/ K y L, Plaza de la Revolución |
| «Hosp» | Hospital Universitario General Calixto García | ❌ | ❌ — 1.ª: Hospital · Cayo Hueso |
| «Hosp» (GPS) | Hospital Universitario General Calixto García | ❌ | ✅ 7.º — 1.ª: Hospital · Cayo Hueso |
| «Cali» (GPS) | Hospital Universitario General Calixto García | ❌ | ✅ 7.º — 1.ª: Calindero · Modelo |
| «Hotel Nac» | Hotel Nacional de Cuba | ❌ | ✅ 1.º — 1.ª: Hotel Nacional de Cuba · Malecón e/ 19 y 23, Plaza de la Revolución |
| «Capi» (GPS) | Capitolio Nacional | ❌ | ✅ 3.º — 1.ª: Capitán · San Juan Pinos |
| «Capi» | Capitolio Nacional | ❌ | ✅ 3.º — 1.ª: Capitán · Luyano Moderno |
| «Obis» (GPS) | Obispo | ✅ 1.º | ✅ 1.º — 1.ª: Obispo · Plaza Vieja |

### Tiempo en PHP (este VPS, PHP 8.3, sin caché; incluye cargar los JSON en cada búsqueda)

| | antes mediana / máx. | después mediana / máx. |
|---|---|---|
| dónde estoy (sin contar la llamada a OSRM) | 14 / 30 ms | 46 / 116 ms |
| dirección escrita | 28 / 50 ms | 20 / 26 ms |
| sugerencias | 23 / 29 ms | 39 / 44 ms |
| cargar habana-calles.json | 20 ms | 39 ms |
| memoria pico | 10 MB | 25 MB |

### Detalle «dónde estoy»

| grupo | zona | esperado | antes | después |
|---|---|---|---|---|
| a mano | Vedado | 23 e/ L y M | ❌ Calle 23 (La Rampa) cerca de Rampa | ✅ Calle 23 e/ L y M |
| a mano | Vedado | 23 e/ L y M | ❌ Calle 23 (La Rampa) | ✅ Calle 23 e/ L y M |
| a mano | Vedado | 23 e/ J y K | ❌ Calle 23 e/ Calle I y Rampa | ✅ Calle 23 e/ J y K |
| a mano | Vedado | 23 e/ J y K | ❌ Calle 23 cerca de Calle K | ✅ Calle 23 e/ J y K |
| a mano | Vedado | 17 e/ 10 y 12 | ❌ Calle 17 cerca de Calle 10 | ✅ Calle 17 e/ 10 y 12 |
| a mano | Vedado | 17 e/ 10 y 12 | ✅ Calle 17 e/ Calle 12 y Calle 10 | ✅ Calle 17 e/ 10 y 12 |
| a mano | Vedado | 17 entre 12 y 14 | ❌ Calle 17 cerca de Calle 10 | ✅ Calle 17 e/ 12 y 14 |
| a mano | Vedado | 17 entre 12 y 14 | ❌ Calle 17 cerca de Calle 12 | ✅ Calle 17 e/ 12 y 14 |
| a mano | Vedado | Línea e/ Paseo y 2 | ❌ Línea (9) cerca de Calle 9 | ✅ Línea e/ Paseo y 2da |
| a mano | Vedado | Línea e/ Paseo y 2 | ❌ Línea (9) cerca de Paseo | ✅ Línea e/ 2da y Paseo |
| a mano | Vedado | L e/ 21 y 23 | ❌ Calle L cerca de Calle 23 | ✅ Calle L e/ 21 y 23 |
| a mano | Vedado | L e/ 21 y 23 | ❌ Calle 23 e/ Calle M y Calle K | ✅ Calle L e/ 21 y 23 |
| a mano | Vedado | Paseo e/ 23 y 25 | ✅ Paseo e/ Calle 23 y Calle 25 | ✅ Paseo e/ 23 y 25 |
| a mano | Vedado | Paseo e/ 23 y 25 | ❌ Paseo e/ Calle 25 y Calle 21 | ✅ Paseo e/ 23 y 25 |
| a mano | Vedado | Calle 23 e/ 10 y 12 | ✅ Calle 23 e/ Calle 10 y Calle 12 | ✅ Calle 23 e/ 10 y 12 |
| a mano | Vedado | Calle 23 e/ 10 y 12 | ✅ Calle 23 e/ Calle 12 y Calle 10 | ✅ Calle 23 e/ 10 y 12 |
| a mano | Vedado | 21 e/ K y L | ❌ Calle 21 esq. Calle K | ✅ Calle 21 e/ K y L |
| a mano | Vedado | 21 e/ K y L | ❌ Calle 21 esq. Calle K | ✅ Calle 21 e/ K y L |
| a mano | Vedado | Calzada e/ D y E | ❌ Calzada (7ma) e/ Calle D y Calzada | ✅ Calzada e/ D y E |
| a mano | Vedado | Calzada e/ D y E | ❌ Calzada (7ma) e/ Calzada y Calle D | ✅ Calzada e/ D y E |
| a mano | Vedado | 13 e/ D y E | ❌ Calle 13 e/ Linea y Calle F | ✅ Calle 13 e/ D y E |
| a mano | Vedado | 13 e/ D y E | ❌ Calle 13 cerca de Calle F | ✅ Calle 13 e/ D y E |
| a mano | Vedado | 19 e/ 4 y 6 | ❌ Calle 19 e/ Calle 8 y Paseo | ✅ Calle 19 e/ 4 y 6 |
| a mano | Vedado | 19 e/ 4 y 6 | ❌ Calle 19 cerca de Calle 8 | ✅ Calle 19 e/ 4 y 6 |
| a mano | Vedado | 25 e/ H e I | ❌ Calle 25 e/ Calle H y Calle J | ✅ Calle 25 e/ H y I |
| a mano | Vedado | 25 e/ H e I | ❌ Calle 25 cerca de Calle J | ✅ Calle 25 e/ H y I |
| a mano | Vedado | G e/ 23 y 25 | ❌ Avenida de los Presidentes (Calle G) cerca de Calle H | ✅ Avenida de los Presidentes e/ 23 y 25 |
| a mano | Vedado | G e/ 23 y 25 | ❌ Avenida de los Presidentes (Calle G) | ✅ Avenida de los Presidentes e/ 23 y 25 |
| a mano | Centro Habana | Galiano e/ San Rafael y San Miguel | ❌ Avenida de Italia (Galiano) | ✅ Avenida de Italia e/ Boulevard de San Rafael y Calzada de San Miguel |
| a mano | Centro Habana | Galiano e/ San Rafael y San Miguel | ❌ Avenida de Italia (Galiano) e/ Italia y Cuchillo | ✅ Avenida de Italia e/ Calzada de San Miguel y Boulevard de San Rafael |
| a mano | Centro Habana | San Rafael e/ Galiano y Águila | ❌ Avenida de Italia (Galiano) | ✅ Boulevard de San Rafael e/ Avenida de Italia y Águila |
| a mano | Centro Habana | San Rafael e/ Galiano y Águila | ❌ San Miguel cerca de Boulevard San Rafael | ✅ Boulevard de San Rafael e/ Águila y Avenida de Italia |
| a mano | Centro Habana | Neptuno e/ Escobar y Gervasio | ❌ Neptuno e/ Escobar y Jose San Martin | ✅ Neptuno e/ Escobar y Gervasio |
| a mano | Centro Habana | Neptuno e/ Escobar y Gervasio | ❌ Neptuno e/ Jose San Martin y Cerrada Paseo | ✅ Neptuno e/ Gervasio y Escobar |
| a mano | Centro Habana | San Lázaro e/ Escobar y Gervasio | ❌ San Lázaro cerca de Perseverancia | ✅ San Lázaro e/ Escobar y Gervasio |
| a mano | Centro Habana | San Lázaro e/ Escobar y Gervasio | ❌ San Lázaro e/ Padre Varela y Perseverancia | ✅ San Lázaro e/ Gervasio y Escobar |
| a mano | Centro Habana | Belascoaín e/ Neptuno y San Miguel | ❌ Padre Varela (Belascoaín) cerca de Belascoain | ✅ Padre Varela e/ Neptuno y Calzada de San Miguel |
| a mano | Centro Habana | Belascoaín e/ Neptuno y San Miguel | ❌ Padre Varela (Belascoaín) | ✅ Padre Varela e/ Calzada de San Miguel y Neptuno |
| a mano | Centro Habana | Concordia e/ Lealtad y Escobar | ❌ Concordia cerca de Campanario | ✅ Concordia e/ Lealtad y Escobar |
| a mano | Centro Habana | Concordia e/ Lealtad y Escobar | ❌ Concordia e/ Lealtad y Padre Varela | ✅ Concordia e/ Escobar y Lealtad |
| a mano | Centro Habana | San Miguel e/ Lealtad y Escobar | ❌ San Miguel cerca de Manrique | ✅ San Miguel e/ Lealtad y Escobar |
| a mano | Centro Habana | San Miguel e/ Lealtad y Escobar | ❌ San Miguel e/ Manrique y Padre Varela | ✅ San Miguel e/ Escobar y Lealtad |
| a mano | Habana Vieja | Obispo e/ Habana y Compostela | ❌ Habana e/ Amargura y Empedrado | ✅ Obispo e/ Habana y Compostela |
| a mano | Habana Vieja | Obispo e/ Habana y Compostela | ❌ Compostela e/ Oreilly y Amargura | ✅ Obispo e/ Compostela y Habana |
| a mano | Habana Vieja | Obispo e/ Bernaza y Villegas | ❌ Obrapía e/ Bernaza y Villegas | ✅ Obispo e/ Bernaza y Villegas |
| a mano | Habana Vieja | Obispo e/ Bernaza y Villegas | ❌ O'Reilly esq. Tejadillo | ✅ Obispo e/ Villegas y Bernaza |
| a mano | Habana Vieja | Obispo e/ Compostela y Aguacate | ❌ Compostela e/ Oreilly y Amargura | ✅ Obispo e/ Compostela y Aguacate |
| a mano | Habana Vieja | Obispo e/ Compostela y Aguacate | ❌ Aguacate esq. San Juan Dios | ✅ Obispo e/ Aguacate y Compostela |
| a mano | Habana Vieja | O'Reilly e/ Habana y Compostela | ❌ O'Reilly e/ Compostela y Aguiar | ✅ O'Reilly e/ Habana y Compostela |
| a mano | Habana Vieja | O'Reilly e/ Habana y Compostela | ❌ O'Reilly e/ Compostela y Aguiar | ✅ O'Reilly e/ Compostela y Habana |
| a mano | Habana Vieja | Obrapía e/ Compostela y Habana | ❌ Obrapía | ✅ Obrapía e/ Compostela y Habana |
| a mano | Habana Vieja | Obrapía e/ Compostela y Habana | ❌ Obrapía | ✅ Obrapía e/ Habana y Compostela |
| a mano | Habana Vieja | Mercaderes e/ Obispo y Obrapía | ❌ Obrapia e/ Obispo y Justiz | ✅ Mercaderes e/ Obispo y Obrapía |
| a mano | Habana Vieja | Mercaderes e/ Obispo y Obrapía | ❌ Obrapia e/ Justiz y Cuba | ✅ Mercaderes e/ Obrapía y Obispo |
| a mano | Habana Vieja | Cuba e/ Empedrado y Tejadillo | ❌ Cuba | ✅ Cuba e/ Empedrado y Tejadillo |
| a mano | Habana Vieja | Cuba e/ Empedrado y Tejadillo | ❌ Cuba | ✅ Cuba e/ Tejadillo y Empedrado |
| a mano | Habana Vieja | Muralla e/ Cuba y San Ignacio | ❌ Cuba esq. Sol | ✅ Muralla e/ Cuba y San Ignacio |
| a mano | Habana Vieja | Muralla e/ Cuba y San Ignacio | ❌ San Ignacio cerca de Amargura | ✅ Muralla e/ San Ignacio y Cuba |
| a mano | Habana Vieja | Compostela e/ Obispo y Obrapía | ❌ Compostela e/ Oreilly y Amargura | ✅ Compostela e/ Obispo y Obrapía |
| a mano | Habana Vieja | Compostela e/ Obispo y Obrapía | ❌ Compostela e/ Oreilly y Amargura | ✅ Compostela e/ Obrapía y Obispo |
| a mano | Playa | 5ta Avenida e/ 42 y 44 | ❌ 5ta Avenida cerca de Calle 42 | ✅ 5ta Avenida e/ 42 y 44 |
| a mano | Playa | 5ta Avenida e/ 42 y 44 | ❌ 5ta Avenida cerca de Calle 42 | ✅ 5ta Avenida e/ 42 y 44 |
| a mano | Playa | 7ma e/ 60 y 62 | ❌ Avenida 7ma cerca de Calle 48 | ✅ Avenida 7ma e/ 60 y 62 |
| a mano | Playa | 7ma e/ 60 y 62 | ❌ Avenida 7ma | ✅ Avenida 7ma e/ 60 y 62 |
| a mano | Playa | 3ra e/ 76 y 78 | ❌ Calle 76 cerca de Calle 5 | ✅ 3ra e/ 76 y 78 |
| a mano | Playa | 3ra e/ 76 y 78 | ❌ 3ra Avenida cerca de Calle 80 | ✅ 3ra Avenida e/ 76 y 78 |
| esquina | Vedado | 23 esq. l | ❌ Calle 23 (La Rampa) | ✅ Calle 23 esq. L |
| esquina | Habana Vieja | obispo esq. bernaza | ✅ Bernaza esq. Obispo | ✅ Bernaza esq. Obispo |
| esquina | Centro Habana | galiano esq. san rafael|boulevard san rafael | ❌ Avenida de Italia (Galiano) cerca de Italia | ✅ Avenida de Italia esq. Boulevard de San Rafael |
| esquina | Vedado | linea esq. paseo | ❌ Línea (9) esq. Linea | ✅ Línea esq. Paseo |
| esquina | Playa | 5 esq. 42 | ✅ 5ta Avenida esq. Calle 42 | ✅ 5ta Avenida esq. 42 |
| esquina | Nuevo Vedado | norte esq. 35 | ❌ Norte esq. Este | ✅ Norte esq. 35 |

### Detalle dirección escrita

| caso | antes | después |
|---|---|---|
| 23 e/ L y M | ✅ esquina a 58 m | ✅ cuadra a 1 m |
| 23 e/ L y M (GPS en Vedado) | ✅ esquina a 58 m | ✅ cuadra a 1 m |
| 23 e/ J y K | ✅ esquina a 35 m | ✅ cuadra a 0 m |
| 23 e/ J y K (GPS en Vedado) | ✅ esquina a 35 m | ✅ cuadra a 0 m |
| 17 e/ 10 y 12 | ❌ cuadra a 18.0 km | ❌ cuadra a 18.0 km |
| 17 e/ 10 y 12 (GPS en Vedado) | ❌ cuadra a 18.0 km | ✅ cuadra a 1 m |
| 17 entre 12 y 14 | ❌ cuadra a 18.0 km | ❌ cuadra a 18.0 km |
| 17 entre 12 y 14 (GPS en Vedado) | ❌ cuadra a 18.0 km | ✅ cuadra a 0 m |
| Línea e/ Paseo y 2 | ✅ esquina a 60 m | ✅ cuadra a 0 m |
| Línea e/ Paseo y 2 (GPS en Vedado) | ✅ esquina a 60 m | ✅ cuadra a 0 m |
| L e/ 21 y 23 | ❌ esquina a 77 m | ✅ cuadra a 1 m |
| L e/ 21 y 23 (GPS en Vedado) | ❌ esquina a 77 m | ✅ cuadra a 1 m |
| Paseo e/ 23 y 25 | ✅ esquina a 61 m | ✅ cuadra a 0 m |
| Paseo e/ 23 y 25 (GPS en Vedado) | ✅ esquina a 61 m | ✅ cuadra a 0 m |
| Calle 23 e/ 10 y 12 | ✅ cuadra a 1 m | ✅ cuadra a 1 m |
| Calle 23 e/ 10 y 12 (GPS en Vedado) | ✅ cuadra a 1 m | ✅ cuadra a 1 m |
| 21 e/ K y L | ✅ esquina a 57 m | ✅ cuadra a 0 m |
| 21 e/ K y L (GPS en Vedado) | ✅ esquina a 57 m | ✅ cuadra a 0 m |
| Calzada e/ D y E | ❌ esquina a 70 m | ✅ cuadra a 1 m |
| Calzada e/ D y E (GPS en Vedado) | ❌ esquina a 70 m | ✅ cuadra a 1 m |
| 13 e/ D y E | ❌ cuadra a 1598 m | ❌ cuadra a 1598 m |
| 13 e/ D y E (GPS en Vedado) | ❌ cuadra a 1598 m | ✅ cuadra a 1 m |
| 19 e/ 4 y 6 | ✅ cuadra a 0 m | ✅ cuadra a 0 m |
| 19 e/ 4 y 6 (GPS en Vedado) | ✅ cuadra a 0 m | ✅ cuadra a 0 m |
| 25 e/ H e I | ❌ no lo encuentra | ✅ esquina a 57 m |
| 25 e/ H e I (GPS en Vedado) | ❌ no lo encuentra | ✅ esquina a 57 m |
| G e/ 23 y 25 | ✅ esquina a 60 m | ✅ cuadra a 1 m |
| G e/ 23 y 25 (GPS en Vedado) | ✅ esquina a 60 m | ✅ cuadra a 1 m |
| Galiano e/ San Rafael y San Miguel | ✅ esquina a 33 m | ✅ cuadra a 1 m |
| Galiano e/ San Rafael y San Miguel (GPS en Centro Habana) | ✅ esquina a 33 m | ✅ cuadra a 1 m |
| San Rafael e/ Galiano y Águila | ✅ esquina a 49 m | ✅ esquina a 49 m |
| San Rafael e/ Galiano y Águila (GPS en Centro Habana) | ✅ esquina a 49 m | ✅ esquina a 49 m |
| Neptuno e/ Escobar y Gervasio | ✅ esquina a 70 m | ✅ cuadra a 0 m |
| Neptuno e/ Escobar y Gervasio (GPS en Centro Habana) | ✅ esquina a 70 m | ✅ cuadra a 0 m |
| San Lázaro e/ Escobar y Gervasio | ✅ esquina a 45 m | ✅ cuadra a 1 m |
| San Lázaro e/ Escobar y Gervasio (GPS en Centro Habana) | ✅ esquina a 45 m | ✅ cuadra a 1 m |
| Belascoaín e/ Neptuno y San Miguel | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Belascoaín e/ Neptuno y San Miguel (GPS en Centro Habana) | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Concordia e/ Lealtad y Escobar | ✅ esquina a 38 m | ✅ cuadra a 1 m |
| Concordia e/ Lealtad y Escobar (GPS en Centro Habana) | ✅ esquina a 38 m | ✅ cuadra a 1 m |
| San Miguel e/ Lealtad y Escobar | ✅ esquina a 37 m | ✅ cuadra a 1 m |
| San Miguel e/ Lealtad y Escobar (GPS en Centro Habana) | ✅ esquina a 37 m | ✅ cuadra a 1 m |
| Obispo e/ Habana y Compostela | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Obispo e/ Habana y Compostela (GPS en Habana Vieja) | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Obispo e/ Bernaza y Villegas | ✅ esquina a 63 m | ✅ cuadra a 0 m |
| Obispo e/ Bernaza y Villegas (GPS en Habana Vieja) | ✅ esquina a 63 m | ✅ cuadra a 0 m |
| Obispo e/ Compostela y Aguacate | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Obispo e/ Compostela y Aguacate (GPS en Habana Vieja) | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| O'Reilly e/ Habana y Compostela | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| O'Reilly e/ Habana y Compostela (GPS en Habana Vieja) | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Obrapía e/ Compostela y Habana | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Obrapía e/ Compostela y Habana (GPS en Habana Vieja) | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Mercaderes e/ Obispo y Obrapía | ✅ esquina a 50 m | ✅ cuadra a 0 m |
| Mercaderes e/ Obispo y Obrapía (GPS en Habana Vieja) | ✅ esquina a 50 m | ✅ cuadra a 0 m |
| Cuba e/ Empedrado y Tejadillo | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Cuba e/ Empedrado y Tejadillo (GPS en Habana Vieja) | ✅ esquina a 40 m | ✅ cuadra a 1 m |
| Muralla e/ Cuba y San Ignacio | ✅ esquina a 44 m | ✅ cuadra a 1 m |
| Muralla e/ Cuba y San Ignacio (GPS en Habana Vieja) | ✅ esquina a 44 m | ✅ cuadra a 1 m |
| Compostela e/ Obispo y Obrapía | ✅ esquina a 38 m | ✅ cuadra a 1 m |
| Compostela e/ Obispo y Obrapía (GPS en Habana Vieja) | ✅ esquina a 38 m | ✅ cuadra a 1 m |
| 5ta Avenida e/ 42 y 44 | ✅ cuadra a 1 m | ✅ cuadra a 1 m |
| 5ta Avenida e/ 42 y 44 (GPS en Playa) | ✅ cuadra a 1 m | ✅ cuadra a 1 m |
| 7ma e/ 60 y 62 | ✅ esquina a 58 m | ✅ cuadra a 0 m |
| 7ma e/ 60 y 62 (GPS en Playa) | ✅ esquina a 58 m | ✅ cuadra a 0 m |
| 3ra e/ 76 y 78 | ✅ esquina a 56 m | ✅ cuadra a 1 m |
| 3ra e/ 76 y 78 (GPS en Playa) | ✅ esquina a 56 m | ✅ cuadra a 1 m |
| 23 y L | ✅ esquina a 0 m | ✅ esquina a 0 m |
| 23 y L (GPS en Vedado) | ✅ esquina a 0 m | ✅ esquina a 0 m |
| Obispo y Bernaza | ✅ esquina a 0 m | ✅ esquina a 0 m |
| Obispo y Bernaza (GPS en Habana Vieja) | ✅ esquina a 0 m | ✅ esquina a 0 m |
| Galiano y San Rafael | ✅ esquina a 0 m | ✅ esquina a 0 m |
| Galiano y San Rafael (GPS en Centro Habana) | ✅ esquina a 0 m | ✅ esquina a 0 m |
| Línea y Paseo | ✅ esquina a 0 m | ✅ esquina a 0 m |
| Línea y Paseo (GPS en Vedado) | ✅ esquina a 0 m | ✅ esquina a 0 m |
| 5ta y 42 | ❌ no lo encuentra | ❌ no lo encuentra |
| 5ta y 42 (GPS en Playa) | ❌ no lo encuentra | ✅ esquina a 0 m |
| Norte y 35 | ❌ esquina a 68 m | ❌ esquina a 68 m |
| Norte y 35 (GPS en Nuevo Vedado) | ❌ esquina a 68 m | ✅ esquina a 0 m |
