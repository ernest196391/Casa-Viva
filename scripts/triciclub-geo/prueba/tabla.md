### «Dónde estoy» (punto del GPS → «X e/ Y y Z»)

| grupo | puntos | antes | después |
|---|---|---|---|
| a mano | 66 | 4/66 (6 %) | 65/66 (98 %) |
| esquina | 6 | 2/6 (33 %) | 6/6 (100 %) |
| automático | 54 | 4/54 (7 %) | 53/54 (98 %) |
| zona Centro Habana | 21 | 0/21 (0 %) | 21/21 (100 %) |
| zona Cerro | 12 | 0/12 (0 %) | 12/12 (100 %) |
| zona Habana Vieja | 25 | 1/25 (4 %) | 25/25 (100 %) |
| zona Nuevo Vedado | 13 | 0/13 (0 %) | 12/13 (92 %) |
| zona Playa | 19 | 4/19 (21 %) | 19/19 (100 %) |
| zona Vedado | 36 | 5/36 (14 %) | 35/36 (97 %) |
| **total** | 126 | **10/126 (8 %)** | **124/126 (98 %)** |

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
| dónde estoy (sin contar la llamada a OSRM) | 10 / 20 ms | 42 / 64 ms |
| dirección escrita | 10 / 14 ms | 20 / 28 ms |
| sugerencias | 9 / 12 ms | 37 / 44 ms |
| cargar habana-calles.json | 14 ms | 21 ms |
| memoria pico | 10 MB | 25 MB |

### Detalle «dónde estoy»

| grupo | zona | esperado | antes | después |
|---|---|---|---|---|
| a mano | Vedado | 23 e/ L y M | ❌ Calle 23 (La Rampa) | ✅ Calle 23 e/ L y M |
| a mano | Vedado | 23 e/ L y M | ❌ Calle 23 (La Rampa) | ✅ Calle 23 e/ L y M |
| a mano | Vedado | 23 e/ J y K | ❌ Calle 23 e/ Calle I y Rampa | ✅ Calle 23 e/ J y K |
| a mano | Vedado | 23 e/ J y K | ❌ Calle 23 e/ Calle I y Rampa | ✅ Calle 23 e/ J y K |
| a mano | Vedado | 17 e/ 10 y 12 | ❌ Calle 17 cerca de Calle 10 | ✅ Calle 17 e/ 10 y 12 |
| a mano | Vedado | 17 e/ 10 y 12 | ✅ Calle 17 e/ Calle 12 y Calle 10 | ✅ Calle 17 e/ 10 y 12 |
| a mano | Vedado | 17 entre 12 y 14 | ❌ Calle 17 cerca de Calle 10 | ✅ Calle 17 e/ 12 y 14 |
| a mano | Vedado | 17 entre 12 y 14 | ❌ Calle 17 cerca de Calle 12 | ✅ Calle 17 e/ 12 y 14 |
| a mano | Vedado | Línea e/ Paseo y 2 | ❌ Línea (9) cerca de Calle 9 | ✅ Línea e/ Paseo y 2da |
| a mano | Vedado | Línea e/ Paseo y 2 | ❌ Línea (9) cerca de Paseo | ✅ Línea e/ 2da y Paseo |
| a mano | Vedado | L e/ 21 y 23 | ❌ Calle L cerca de Calle 23 | ✅ Calle L e/ 21 y 23 |
| a mano | Vedado | L e/ 21 y 23 | ❌ Calle L cerca de Calle 23 | ✅ Calle L e/ 21 y 23 |
| a mano | Vedado | Paseo e/ 23 y 25 | ✅ Paseo e/ Calle 23 y Calle 25 | ❌ Paseo e/ 19 y 25 |
| a mano | Vedado | Paseo e/ 23 y 25 | ❌ Paseo e/ Calle 25 y Calle 21 | ✅ Paseo e/ 23 y 25 |
| a mano | Vedado | Calle 23 e/ 10 y 12 | ✅ Calle 23 e/ Calle 10 y Calle 12 | ✅ Calle 23 e/ 10 y 12 |
| a mano | Vedado | Calle 23 e/ 10 y 12 | ✅ Calle 23 e/ Calle 12 y Calle 10 | ✅ Calle 23 e/ 10 y 12 |
| a mano | Vedado | 21 e/ K y L | ❌ Calle 21 esq. Calle K | ✅ Calle 21 e/ K y L |
| a mano | Vedado | 21 e/ K y L | ❌ Calle 21 esq. Calle K | ✅ Calle 21 e/ K y L |
| a mano | Vedado | Calzada e/ D y E | ❌ Calzada (7ma) cerca de Calle D | ✅ Calzada e/ D y E |
| a mano | Vedado | Calzada e/ D y E | ❌ Calzada (7ma) e/ Calle D y Presidentes | ✅ Calzada e/ D y E |
| a mano | Vedado | 13 e/ D y E | ❌ Calle 13 e/ Linea y Calle F | ✅ Calle 13 e/ D y E |
| a mano | Vedado | 13 e/ D y E | ❌ Calle 13 cerca de Calle F | ✅ Calle 13 e/ D y E |
| a mano | Vedado | 19 e/ 4 y 6 | ❌ Calle 19 e/ Calle 8 y Paseo | ✅ Calle 19 e/ 4 y 6 |
| a mano | Vedado | 19 e/ 4 y 6 | ❌ Calle 19 cerca de Calle 8 | ✅ Calle 19 e/ 4 y 6 |
| a mano | Vedado | 25 e/ H e I | ❌ Calle 25 e/ Presidentes y Calle J | ✅ Calle 25 e/ H y I |
| a mano | Vedado | 25 e/ H e I | ❌ Calle 25 cerca de Calle J | ✅ Calle 25 e/ H y I |
| a mano | Vedado | G e/ 23 y 25 | ❌ Avenida de los Presidentes (Calle G) cerca de Calle H | ✅ Avenida de los Presidentes e/ 23 y 25 |
| a mano | Vedado | G e/ 23 y 25 | ❌ Avenida de los Presidentes (Calle G) e/ Presidentes y Calle 23 | ✅ Avenida de los Presidentes e/ 23 y 25 |
| a mano | Centro Habana | Galiano e/ San Rafael y San Miguel | ❌ Avenida de Italia (Galiano) cerca de Italia | ✅ Avenida de Italia e/ Boulevard de San Rafael y Calzada de San Miguel |
| a mano | Centro Habana | Galiano e/ San Rafael y San Miguel | ❌ Avenida de Italia (Galiano) e/ Italia y Cuchillo | ✅ Avenida de Italia e/ Calzada de San Miguel y Boulevard de San Rafael |
| a mano | Centro Habana | San Rafael e/ Galiano y Águila | ❌ Avenida de Italia (Galiano) | ✅ Boulevard de San Rafael e/ Avenida de Italia y Águila |
| a mano | Centro Habana | San Rafael e/ Galiano y Águila | ❌ Águila | ✅ Boulevard de San Rafael e/ Águila y Avenida de Italia |
| a mano | Centro Habana | Neptuno e/ Escobar y Gervasio | ❌ Neptuno e/ Escobar y Jose San Martin | ✅ Neptuno e/ Escobar y Gervasio |
| a mano | Centro Habana | Neptuno e/ Escobar y Gervasio | ❌ Neptuno e/ Jose San Martin y Cerrada Paseo | ✅ Neptuno e/ Gervasio y Escobar |
| a mano | Centro Habana | San Lázaro e/ Escobar y Gervasio | ❌ San Lázaro cerca de Perseverancia | ✅ San Lázaro e/ Escobar y Gervasio |
| a mano | Centro Habana | San Lázaro e/ Escobar y Gervasio | ❌ San Lázaro e/ Padre Varela y Perseverancia | ✅ San Lázaro e/ Gervasio y Escobar |
| a mano | Centro Habana | Belascoaín e/ Neptuno y San Miguel | ❌ Padre Varela (Belascoaín) | ✅ Padre Varela e/ Neptuno y Calzada de San Miguel |
| a mano | Centro Habana | Belascoaín e/ Neptuno y San Miguel | ❌ Padre Varela (Belascoaín) | ✅ Padre Varela e/ Calzada de San Miguel y Neptuno |
| a mano | Centro Habana | Concordia e/ Lealtad y Escobar | ❌ Concordia cerca de Lealtad | ✅ Concordia e/ Lealtad y Escobar |
| a mano | Centro Habana | Concordia e/ Lealtad y Escobar | ❌ Concordia e/ Lealtad y Padre Varela | ✅ Concordia e/ Escobar y Lealtad |
| a mano | Centro Habana | San Miguel e/ Lealtad y Escobar | ❌ San Miguel cerca de Manrique | ✅ San Miguel e/ Lealtad y Escobar |
| a mano | Centro Habana | San Miguel e/ Lealtad y Escobar | ❌ San Miguel e/ Manrique y Padre Varela | ✅ San Miguel e/ Escobar y Lealtad |
| a mano | Habana Vieja | Obispo e/ Habana y Compostela | ❌ Habana e/ Amargura y Empedrado | ✅ Obispo e/ Habana y Compostela |
| a mano | Habana Vieja | Obispo e/ Habana y Compostela | ❌ Compostela e/ Oreilly y Amargura | ✅ Obispo e/ Compostela y Habana |
| a mano | Habana Vieja | Obispo e/ Bernaza y Villegas | ❌ Bernaza cerca de Misiones | ✅ Obispo e/ Bernaza y Villegas |
| a mano | Habana Vieja | Obispo e/ Bernaza y Villegas | ❌ O'Reilly esq. Tejadillo | ✅ Obispo e/ Villegas y Bernaza |
| a mano | Habana Vieja | Obispo e/ Compostela y Aguacate | ❌ Compostela e/ Oreilly y Amargura | ✅ Obispo e/ Compostela y Aguacate |
| a mano | Habana Vieja | Obispo e/ Compostela y Aguacate | ❌ Aguacate esq. San Juan Dios | ✅ Obispo e/ Aguacate y Compostela |
| a mano | Habana Vieja | O'Reilly e/ Habana y Compostela | ❌ O'Reilly e/ Compostela y Aguiar | ✅ O'Reilly e/ Habana y Compostela |
| a mano | Habana Vieja | O'Reilly e/ Habana y Compostela | ❌ O'Reilly e/ Compostela y Aguiar | ✅ O'Reilly e/ Compostela y Habana |
| a mano | Habana Vieja | Obrapía e/ Compostela y Habana | ❌ Obrapía | ✅ Obrapía e/ Compostela y Habana |
| a mano | Habana Vieja | Obrapía e/ Compostela y Habana | ❌ Obrapía | ✅ Obrapía e/ Habana y Compostela |
| a mano | Habana Vieja | Mercaderes e/ Obispo y Obrapía | ❌ Obrapia e/ Justiz y Cuba | ✅ Mercaderes e/ Obispo y Obrapía |
| a mano | Habana Vieja | Mercaderes e/ Obispo y Obrapía | ❌ Obrapia e/ Justiz y Cuba | ✅ Mercaderes e/ Obrapía y Obispo |
| a mano | Habana Vieja | Cuba e/ Empedrado y Tejadillo | ❌ Cuba | ✅ Cuba e/ Empedrado y Tejadillo |
| a mano | Habana Vieja | Cuba e/ Empedrado y Tejadillo | ❌ Cuba | ✅ Cuba e/ Tejadillo y Empedrado |
| a mano | Habana Vieja | Muralla e/ Cuba y San Ignacio | ❌ Muralla cerca de San Ignacio | ✅ Muralla e/ Cuba y San Ignacio |
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
| automático | Nuevo Vedado | Santa Ana e/ loma y bellavista | ❌ Santa Ana | ✅ Santa Ana e/ Loma y Bellavista |
| automático | Nuevo Vedado | Almendares e/ puentes grandes y santa rosa | ❌ Almendares esq. Puentes Grandes | ✅ Almendares e/ Santa Rosa y Calzada de Puentes Grandes |
| automático | Nuevo Vedado | Avenida de Colón e/ 39 y callejon protestantes/san antonio chiquito | ❌ Avenida de Colón e/ Protestantes y Callejon Protestantes | ❌ Avenida de Colón e/ Callejón de los Protestantes y Protestantes |
| automático | Nuevo Vedado | Tulipán e/ loma y 47 | ❌ Tulipán cerca de Calle 39 | ✅ Tulipán e/ Loma y 47 |
| automático | Nuevo Vedado | Recurso e/ mazarredo y ulloa | ❌ Recurso esq. Empirio | ✅ Recurso e/ Mazarredo y Ulloa |
| automático | Nuevo Vedado | Avenida de la Independencia e/ santa ana y talleres | ❌ Avenida de la Independencia (Avenida de Rancho Boyeros) cerca de Rancho Boyeros | ✅ Avenida de la Independencia e/ Santa Ana y Talleres |
| automático | Nuevo Vedado | Santa Ana e/ 47 y loma | ❌ Santa Ana | ✅ Santa Ana e/ 47 y Loma |
| automático | Nuevo Vedado | Factor e/ lombillo y tulipan | ❌ Tulipan | ✅ Factor e/ Lombillo y Tulipán |
| automático | Nuevo Vedado | Avenida 26 e/ recurso y reparto | ❌ Avenida 26 cerca de Acceso A Zoologico 26 | ✅ Avenida 26 e/ Recurso y Reparto |
| automático | Nuevo Vedado | Avenida del Río e/ 30/35 y 37 | ❌ Avenida del Río esq. Calle 30 | ✅ Avenida del Río e/ 35 y 37 |
| automático | Nuevo Vedado | 37 e/ 24/protestantes y torre | ❌ 37 cerca de Calle 24 | ✅ 37 e/ 24 y La Torre |
| automático | Nuevo Vedado | Avenida de la Independencia e/ cerro y ayestaran/paradero | ❌ Avenida de la Independencia (Avenida de Rancho Boyeros) e/ Ayestaran y Puentes Grandes | ✅ Avenida de la Independencia e/ Ayestarán y Calzada del Cerro |
| automático | Playa | Avenida 25 e/ 48 y 46 | ❌ Avenida 25 e/ Calle 50 y Calle 27 | ✅ Avenida 25 e/ 46 y 48 |
| automático | Playa | Avenida 9na e/ 23/26 y 24/25 | ❌ Avenida 9na cerca de Calle 20 | ✅ Avenida 9na e/ 24 y 26 |
| automático | Playa | 30 e/ 7 y 19/9 | ❌ 30 cerca de Calle 19 | ✅ 30 e/ 7ma y 9na |
| automático | Playa | 48 e/ 11 y 13 | ✅ 48 e/ Calle 13 y Calle 11 | ✅ 48 e/ 11 y 13 |
| automático | Playa | 5ta Avenida e/ 36 y 38 | ❌ 5ta Avenida cerca de Calle 38 | ✅ 5ta Avenida e/ 36 y 38 |
| automático | Playa | Avenida 31 e/ 34 y 30/31 a | ✅ Avenida 31 e/ Calle 34 y Calle 30 | ✅ Avenida 31 e/ 30 y 34 |
| automático | Playa | Calle 26 e/ 25 y 27 | ❌ Calle 26 e/ Calle 9 y Calle 29 | ✅ Calle 26 e/ 25 y 27 |
| automático | Playa | Avenida 27 e/ 30 y 34 | ❌ Avenida 27 cerca de Calle 36 | ✅ Avenida 27 e/ 30 y 34 |
| automático | Playa | Avenida 15 e/ 48 y 50 | ❌ Avenida 15 cerca de Calle 52 | ✅ Avenida 15 e/ 48 y 50 |
| automático | Playa | 42 e/ 13/38 y 11 | ✅ 42 e/ Calle 11 y Calle 13 | ✅ 42 e/ 11 y 38 |
| automático | Playa | Calle 60 e/ 11 y 13 | ❌ Calle 60 e/ Calle 13 y Calle 48 | ✅ Calle 60 e/ 11 y 13 |
| automático | Playa | 48 e/ 15 y 17 | ❌ 48 cerca de Calle 15 | ✅ 48 e/ 15 y 17 |
| automático | Cerro | Cruz del Padre e/ cerro y carballo | ❌ Cruz del Padre e/ Cerro y Velazquez | ✅ Cruz del Padre e/ Calzada del Cerro y Carballo |
| automático | Cerro | Zapote e/ san benigno y san indalecio | ❌ Zapote esq. Santa Irene | ✅ Zapote e/ San Indalecio y San Benigno |
| automático | Cerro | Magnolia e/ san anselmo y san gabriel | ❌ Magnolia esq. Cerezo | ✅ Magnolia e/ San Gabriel y San Anselmo |
| automático | Cerro | San Benigno e/ encarnacion y correa | ❌ San Benigno e/ Alcalde Ofarrill y Santa Irene | ✅ San Benigno e/ Encarnación y Correa |
| automático | Cerro | Flores e/ encarnacion y cocos/consejal veiga/general lee | ❌ Flores esq. Consejal Veiga | ✅ Flores e/ Encarnación y General Lee |
| automático | Cerro | Zapote e/ paz y san julio | ❌ Zapote e/ Durege y Gomez | ✅ Zapote e/ San Julio y Paz |
| automático | Cerro | Calzada del Cerro e/ pineras y dominguez | ❌ Calzada del Cerro | ✅ Calzada del Cerro e/ Piñeras y Domínguez |
| automático | Cerro | San Gabriel e/ cerezo y macedonia | ❌ San Gabriel esq. Macedonia | ✅ San Gabriel e/ Cerezo y Macedonia |
| automático | Cerro | Vega e/ serafines y tamarindo | ❌ Vega (General Serrano) cerca de Tamarindo | ✅ Vega e/ Serafines y Tamarindo |
| automático | Cerro | Santa Emilia e/ gomez y mendoza | ❌ Santa Emilia esq. Lateral Via Blanca | ✅ Santa Emilia e/ Mendoza y Gómez |
| automático | Cerro | San Pablo e/ vista hermosa y falgueras | ❌ San Pablo cerca de Vista Hermosa | ✅ San Pablo e/ Vista Hermosa y Falgueras |
| automático | Cerro | San Indalecio e/ tamarindo y rodriguez | ❌ San Indalecio cerca de San Leonardo | ✅ San Indalecio e/ Calle Rodríguez y Tamarindo |
| automático | Vedado | Línea e/ 2 y paseo | ❌ Línea (9) cerca de Paseo | ✅ Línea e/ 2da y Paseo |
| automático | Vedado | Calle 21 e/ b y c | ❌ Calle 21 cerca de Paseo | ✅ Calle 21 e/ B y C |
| automático | Vedado | Calle 15 e/ l y k | ❌ Calle 15 e/ Calle K y Calle N | ✅ Calle 15 e/ K y L |
| automático | Vedado | Calle 5ta e/ c y d | ✅ Calle 5ta e/ Calle D y Calle C | ✅ Calle 5ta e/ C y D |
| automático | Vedado | Calzada e/ i y j | ❌ Calzada (7) cerca de Calzada | ✅ Calzada e/ I y J |
| automático | Vedado | Calle 19 e/ k y j | ❌ Calle 19 cerca de Calle H | ✅ Calle 19 e/ J y K |
| automático | Centro Habana | San Francisco e/ san rafael y san miguel | ❌ San Francisco cerca de San Rafael | ✅ San Francisco e/ San Rafael y Calzada de San Miguel |
| automático | Centro Habana | Vapor e/ espada y hospital | ❌ Vapor e/ Espada y Aramburu | ✅ Vapor e/ Hospital y Espada |
| automático | Centro Habana | San Lázaro e/ gervasio y escobar | ❌ San Lázaro cerca de Perseverancia | ✅ San Lázaro e/ Escobar y Gervasio |
| automático | Centro Habana | Espada e/ vapor y 27 noviembre/jovellar | ❌ Espada e/ Hospital y Calle 25 | ✅ Espada e/ 27 de Noviembre y Vapor |
| automático | Centro Habana | Lealtad e/ virtudes y animas | ❌ Lealtad esq. Lagunas | ✅ Lealtad e/ Virtudes y Ánimas |
| automático | Centro Habana | Avenida de Italia e/ neptuno y san miguel | ❌ Avenida de Italia (Galiano) esq. Galiano | ✅ Avenida de Italia e/ Neptuno y Calzada de San Miguel |
| automático | Habana Vieja | Habana e/ pena pobre y belgica/capdevila/misiones | ❌ Habana esq. Compostela | ✅ Habana e/ Capdevila y Peña Pobre |
| automático | Habana Vieja | Coloma e/ carretera cocos y echarte/marina | ❌ Coloma esq. Carretera Cabana | ✅ Coloma e/ Echarte y Carretera de Los Cocos |
| automático | Habana Vieja | Tejadillo e/ compostela y habana | ❌ Tejadillo e/ Habana y Trocadero | ✅ Tejadillo e/ Habana y Compostela |
| automático | Habana Vieja | Obispo e/ aguacate y compostela | ❌ Aguacate esq. San Juan Dios | ✅ Obispo e/ Compostela y Aguacate |
| automático | Habana Vieja | Amargura e/ aguiar y cuba | ❌ Amargura | ✅ Amargura e/ Cuba y Aguiar |
| automático | Habana Vieja | Dragones e/ agramonte/zulueta y paseo marti/paseo prado | ❌ Dragones cerca de Agramonte | ✅ Dragones e/ Agramonte y Paseo de Martí |

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
