# Instrucciones de Despliegue

Esta guía explica cómo desplegar la API en un servidor Apache, enfocándose en la seguridad y las prácticas estándar.

## Paso 0: Configuración del Entorno

Antes de desplegar, necesitas configurar tus variables de entorno.

1.  **Crea tu archivo `.env`**: Este proyecto utiliza un archivo `.env` para gestionar los secretos y la configuración de la base de datos. Copia el archivo de ejemplo proporcionado para empezar.
    ```bash
    cp .env.example .env
    ```
2.  **Edita `.env`**: Abre el nuevo archivo `.env` y rellena los valores correctos para tu base de datos (host, nombre, usuario, contraseña) y define un `JWT_SECRET` único y seguro.

**Importante**: El archivo `.env` contiene información sensible y nunca debe ser subido a un repositorio público. Está incluido en el archivo `.gitignore` del proyecto para prevenir que sea versionado accidentalmente.

---

## Método Recomendado: Raíz Web Apuntando a `public`

Este es el método más seguro y profesional.

1.  **Sube el proyecto completo** (incluyendo tu archivo `.env` recién creado) a un directorio fuera de la raíz web pública (ej. fuera de `htdocs` o `public_html`). Por ejemplo, a `/var/www/mi_api`.
2.  **Configura tu Virtual Host de Apache** para que el `DocumentRoot` apunte directamente a la carpeta `public` de tu proyecto.

    ```apache
    <VirtualHost *:80>
        ServerName tu-dominio.com
        DocumentRoot /var/www/mi_api/public

        <Directory /var/www/mi_api/public>
            AllowOverride All
            Require all granted
        </Directory>
    </VirtualHost>
    ```
3.  Reinicia Apache. Con esta configuración, solo el contenido de `public` es accesible desde la web, protegiendo todo el código fuente y el archivo `.env`.

---

## Método para Hosting Compartido (Sin acceso para cambiar el `DocumentRoot`)

Usa este método si solo puedes subir archivos a `htdocs`.

1.  **Copia el código fuente en un lugar seguro**: Sube la carpeta `App` y tu archivo `.env` a un directorio *fuera* de `htdocs`. Por ejemplo, crea una carpeta `api_source` al mismo nivel que `htdocs`.

2.  **Copia el punto de entrada a `htdocs`**: Copia el contenido de la carpeta `public` (`index.php` y `.htaccess`) directamente dentro de `htdocs`.

    La estructura de archivos debería quedar así:
    ```
    / (directorio raíz de tu hosting)
    ├── api_source/
    │   ├── .env      <-- Tu archivo con los secretos
    │   └── App/
    └── htdocs/       <-- Tu raíz web pública
        ├── .htaccess
        └── index.php
    ```

3.  **Modifica `index.php`**: Abre el archivo `index.php` que está **dentro de `htdocs`** y ajusta las rutas de los `require_once` para que apunten a la carpeta `api_source`.

    **Cambia esto:**
    ```php
    require_once __DIR__ . '/../App/Infrastructure/Config.php';
    // ... y el resto de requires
    ```

    **Por esto:**
    ```php
    require_once __DIR__ . '/../api_source/App/Infrastructure/Config.php';
    // ... y el resto de requires
    ```
