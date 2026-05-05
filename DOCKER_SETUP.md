# Docker Setup for WashHub

This guide explains how to run WashHub using Docker and Docker Compose.

## Prerequisites

- Docker Desktop installed on your machine
- Docker Compose (usually included with Docker Desktop)

## Quick Start

1. **Clone the repository** (if you haven't already)
   ```bash
   git clone <your-repo-url>
   cd "WASHING BAY MANAGEMENT SYSTEM(SAAS)"
   ```

2. **Build and start the containers**
   ```bash
   docker-compose up -d
   ```

3. **Access the application**
   - Main Application: http://localhost:8080
   - phpMyAdmin: http://localhost:8081
     - Username: root
     - Password: rootpassword

## Services

The Docker Compose setup includes three services:

### 1. Web Service (washhub-web)
- **Image**: Custom PHP 8.2 with Apache
- **Port**: 8080 (mapped to container port 80)
- **Purpose**: Runs the PHP application
- **Volumes**: 
  - `./carwash:/var/www/html` - Live code mounting for development
  - `./frontend:/var/www/frontend` - Frontend assets

### 2. Database Service (washhub-db)
- **Image**: MySQL 8.0
- **Port**: 3306
- **Purpose**: Runs the MySQL database
- **Environment Variables**:
  - `MYSQL_ROOT_PASSWORD=rootpassword`
  - `MYSQL_DATABASE=carwash_db`
- **Volume**: `mysql-data` - Persists database data

### 3. phpMyAdmin (washhub-phpmyadmin)
- **Image**: phpMyAdmin
- **Port**: 8081
- **Purpose**: Database management interface
- **Environment Variables**:
  - `PMA_HOST=db`
  - `PMA_PORT=3306`
  - `PMA_USER=root`
  - `PMA_PASSWORD=rootpassword`

## Configuration

### Database Connection

The application is pre-configured to connect to the Docker MySQL database. The environment variables are set in `docker-compose.yml`:

```yaml
environment:
  - DB_HOST=db
  - DB_PORT=3306
  - DB_NAME=carwash_db
  - DB_USERNAME=root
  - DB_PASSWORD=rootpassword
  - APP_NAME=WashHub
  - APP_URL=http://localhost:8080
```

### Changing Database Passwords

To change the database password, update both:
1. The `MYSQL_ROOT_PASSWORD` in the `db` service in `docker-compose.yml`
2. The `DB_PASSWORD` environment variable in the `web` service

Then rebuild:
```bash
docker-compose down
docker-compose up -d --build
```

## Common Commands

### Start all services
```bash
docker-compose up -d
```

### Stop all services
```bash
docker-compose down
```

### View logs
```bash
# All services
docker-compose logs

# Specific service
docker-compose logs web
docker-compose logs db
```

### Restart a service
```bash
docker-compose restart web
```

### Rebuild after code changes
```bash
docker-compose up -d --build
```

### Access container shell (for debugging)
```bash
docker-compose exec web bash
```

### Execute PHP commands inside container
```bash
docker-compose exec web php -v
docker-compose exec web php -m
```

## Database Initialization

The MySQL service will automatically create the `carwash_db` database on first startup. You can use phpMyAdmin at http://localhost:8081 to:
- Import your database schema
- Manage tables
- Run queries

## Troubleshooting

### Port Already in Use
If port 8080 or 3306 is already in use, modify the ports in `docker-compose.yml`:
```yaml
ports:
  - "8081:80"  # Change 8080 to another port
```

### Permission Issues
If you encounter permission issues with file uploads or cache, you may need to adjust permissions:
```bash
docker-compose exec web chown -R www-data:www-data /var/www/html
```

### Database Connection Failed
- Ensure the `db` service is running: `docker-compose ps`
- Check the database logs: `docker-compose logs db`
- Verify environment variables match between `web` and `db` services

### Clearing Everything
To completely remove all containers, volumes, and networks:
```bash
docker-compose down -v
```

## Production Deployment

For production deployment, consider:

1. **Remove phpMyAdmin** - It's a security risk in production
2. **Change default passwords** - Use strong, unique passwords
3. **Use environment variables file** - Create `.env` file and use it in docker-compose.yml
4. **Enable HTTPS** - Use a reverse proxy like Nginx with SSL certificates
5. **Volume backups** - Regularly backup the MySQL volume
6. **Resource limits** - Add memory and CPU limits to services

Example production docker-compose.yml changes:
```yaml
services:
  # Remove phpmyadmin service
  # Add resource limits
  web:
    deploy:
      resources:
        limits:
          cpus: '1'
          memory: 512M
```

## Notes

- The current setup uses live code mounting (`./carwash:/var/www/html`) for development. For production, copy the code into the image instead.
- The `.dockerignore` file prevents unnecessary files from being copied into the Docker image.
- Database data persists in the `mysql-data` Docker volume even after containers are stopped.

## Support

For issues or questions, refer to the main README.md or contact the development team.
