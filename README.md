# Nexus Plays

Sistema de Gestión de Contenidos (CMS) desarrollado con Laravel para la administración y publicación de noticias sobre videojuegos, inspirado en portales como IGN, Vandal y 3DJuegos.

## Descripción

Nexus Plays es un CMS que permite a administradores y editores gestionar noticias relacionadas con videojuegos, mientras que los usuarios registrados pueden interactuar mediante comentarios. Los visitantes pueden consultar libremente las noticias, pero no realizar comentarios.

El proyecto fue desarrollado como parte de la asignatura **Desarrollo de Software Seguro**, aplicando metodologías ágiles (Scrum), control de versiones con Git/GitHub y buenas prácticas de seguridad en Laravel.

---

# Características

- Registro de usuarios.
- Inicio y cierre de sesión.
- Recuperación de contraseña.
- CRUD de noticias.
- CRUD de comentarios.
- Dashboard administrativo.
- Gestión de usuarios.
- Gestión de roles.
- Auditoría de acciones.
- Protección mediante Middleware y Policies.
- Protección CSRF.
- Validación de datos.
- Prevención de ataques XSS.

---

# Tecnologías utilizadas

| Tecnología | Versión |
|------------|---------|
| Laravel | 12 |
| PHP | 8.x |
| Blade | Incluido en Laravel |
| Bootstrap | 5 |
| MySQL | 8 |
| Composer | Última versión estable |
| Git | Control de versiones |
| GitHub | Repositorio remoto |
| Jira | Gestión del proyecto |

---

# Estructura del proyecto

```text
app/
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   └── Requests/
├── Models/
resources/
├── views/
routes/
database/
public/
```

---

# Instalación

## 1. Clonar el repositorio

```bash
git clone https://github.com/salgad-o/Proyecto-Laravel.git
```

## 2. Entrar al proyecto

```bash
cd nexus-plays
```

## 3. Instalar dependencias

```bash
composer install
```

## 4. Copiar el archivo de entorno

```bash
cp .env.example .env
```

## 5. Generar la clave de Laravel

```bash
php artisan key:generate
```

## 6. Configurar la base de datos

Editar el archivo `.env` con las credenciales locales.

## 7. Ejecutar migraciones

```bash
php artisan migrate
```

## 8. Iniciar el servidor

```bash
php artisan serve
```

---

# Roles del sistema

### Visitante

- Consultar noticias.
- Leer comentarios.

### Usuario registrado

- Publicar comentarios.
- Editar sus comentarios.
- Eliminar sus comentarios.

### Editor

- Crear noticias.
- Editar noticias.
- Eliminar noticias.

### Administrador

- Gestión de usuarios.
- Gestión de roles.
- Gestión de noticias.
- Auditoría.
- Dashboard.

---

# Seguridad implementada

- Hash de contraseñas mediante Bcrypt.
- Protección CSRF.
- Middleware de autenticación.
- Policies de autorización.
- Validación de formularios.
- Escape automático de Blade contra XSS.
- Control de acceso por roles.

---

# Planeación del proyecto

El proyecto fue organizado mediante Scrum utilizando Jira.

Se definieron:

- 8 Épicas.
- 22 Historias de Usuario.
- 3 Sprints.
- Tablero Kanban para el seguimiento del desarrollo.

---

# Flujo de ramas

El proyecto utiliza dos ramas principales.

| Rama | Propósito |
|-------|-----------|
| `main` | Versión estable del proyecto. |
| `nexus-plays` | Desarrollo e integración de nuevas funcionalidades. |

---

# Convención de commits

Se utiliza una convención basada en prefijos para facilitar la trazabilidad.

| Prefijo | Uso |
|----------|-----|
| feat | Nueva funcionalidad |
| fix | Corrección de errores |
| security | Mejoras de seguridad |
| docs | Documentación |
| refactor | Reestructuración del código |
| style | Cambios visuales |
| test | Pruebas |

Ejemplos:

```bash
feat: implementar CRUD de noticias

feat: agregar módulo de comentarios

security: implementar protección CSRF

fix: corregir validación del login

docs: actualizar README

refactor: reorganizar controladores
```

---

# Integrantes

- Andrés Carrillo
- Alejandro Salgado

---

# Licencia

Proyecto desarrollado con fines académicos para la asignatura **Desarrollo de Software Seguro**.