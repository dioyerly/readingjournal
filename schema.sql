-- Opcional: la app crea estas tablas sola la primera vez que alguien entra.
-- Si preferís hacerlo a mano: hPanel → Bases de datos → phpMyAdmin → Importar este archivo.
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL DEFAULT '',
  pass_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS states (
  user_id INT UNSIGNED PRIMARY KEY,
  version INT UNSIGNED NOT NULL DEFAULT 0,
  data MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_states_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  at DATETIME NOT NULL,
  INDEX idx_ip_at (ip, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
