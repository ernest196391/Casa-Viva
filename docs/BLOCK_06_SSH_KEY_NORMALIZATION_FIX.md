# Bloque 06 — normalización de clave SSH en GitHub Actions

## Problema observado

El despliegue del prototipo llegaba correctamente hasta preparar SSH, pero `scp` fallaba con `Load key ... error in libcrypto` al usar una clave privada copiada desde Windows a GitHub Secrets.

## Corrección

El workflow `Deploy prototype Casa Viva` normaliza la clave privada antes de usarla:

- elimina `\r` para convertir CRLF a LF;
- garantiza salto de línea final;
- valida que OpenSSH pueda derivar la clave pública con `ssh-keygen -y` antes de intentar cualquier copia remota.

Si la clave sigue siendo inválida, el job falla durante `Preparar SSH`, antes de `scp` y antes de cualquier cambio en Hostinger.

## Garantía

La prueba `artifacts/tests/test-prototype-deploy-6b.mjs` exige que la normalización y la validación de la clave permanezcan en el contrato de despliegue.
