-- Bookwat · MySQL / MariaDB (utf8mb4)
-- No hace falta importarlo: la app crea estas tablas sola la primera vez que se usa.
-- (Si preferís hacerlo a mano: hPanel → Bases de datos → phpMyAdmin → Importar este archivo.)

CREATE TABLE IF NOT EXISTS cuentas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  nombre VARCHAR(120) NOT NULL DEFAULT '',
  pass_hash VARCHAR(255) NOT NULL,
  override ENUM('auto','activo','bloqueado') NOT NULL DEFAULT 'auto',
  session_ver INT UNSIGNED NOT NULL DEFAULT 1,
  creada DATETIME NOT NULL,
  ultimo_login DATETIME NULL,
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suscripciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  preapproval_id VARCHAR(80) NOT NULL,
  cuenta_id INT UNSIGNED NULL,
  payer_email VARCHAR(190) NOT NULL DEFAULT '',
  estado VARCHAR(30) NOT NULL DEFAULT 'pending',
  motivo VARCHAR(255) NOT NULL DEFAULT '',
  monto_cent BIGINT NOT NULL DEFAULT 0,
  moneda VARCHAR(8) NOT NULL DEFAULT 'ARS',
  periodo_dias INT NOT NULL DEFAULT 30,
  proximo_cobro DATETIME NULL,
  mp_creada DATETIME NULL,
  mp_modificada DATETIME NULL,
  creada DATETIME NOT NULL,
  actualizada DATETIME NOT NULL,
  UNIQUE KEY uq_pre (preapproval_id),
  KEY ix_cuenta (cuenta_id),
  KEY ix_email (payer_email),
  CONSTRAINT fk_sus_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuentas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pagos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mp_id VARCHAR(80) NOT NULL,
  preapproval_id VARCHAR(80) NOT NULL,
  estado VARCHAR(30) NOT NULL,
  detalle VARCHAR(80) NOT NULL DEFAULT '',
  monto_cent BIGINT NOT NULL DEFAULT 0,
  moneda VARCHAR(8) NOT NULL DEFAULT 'ARS',
  reintento INT NOT NULL DEFAULT 0,
  fecha_debito DATETIME NULL,
  creada DATETIME NOT NULL,
  actualizada DATETIME NOT NULL,
  UNIQUE KEY uq_mp (mp_id),
  KEY ix_pre (preapproval_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS eventos_mp (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recibido DATETIME NOT NULL,
  topic VARCHAR(60) NOT NULL DEFAULT '',
  data_id VARCHAR(80) NOT NULL DEFAULT '',
  firma_ok TINYINT(1) NOT NULL DEFAULT 0,
  resultado VARCHAR(30) NOT NULL DEFAULT '',
  detalle VARCHAR(255) NOT NULL DEFAULT '',
  KEY ix_rec (recibido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recuperaciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cuenta_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expira DATETIME NOT NULL,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_tok (token_hash),
  CONSTRAINT fk_rec_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuentas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_intentos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave CHAR(64) NOT NULL,
  ambito VARCHAR(12) NOT NULL,
  ts DATETIME NOT NULL,
  KEY ix_clave (clave, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS biblioteca_estado (
  cuenta_id INT UNSIGNED NOT NULL PRIMARY KEY,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  data MEDIUMTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  CONSTRAINT fk_est_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuentas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS biblioteca_respaldos (
  cuenta_id INT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  data MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (cuenta_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auditoria (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL,
  actor VARCHAR(190) NOT NULL,
  accion VARCHAR(60) NOT NULL,
  detalle VARCHAR(500) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  KEY ix_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS landing_eventos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts DATETIME NOT NULL,
  dia DATE NOT NULL,
  tipo VARCHAR(20) NOT NULL,
  visitante CHAR(16) NOT NULL,
  origen VARCHAR(60) NOT NULL DEFAULT '',
  campana VARCHAR(60) NOT NULL DEFAULT '',
  disp VARCHAR(8) NOT NULL DEFAULT '',
  detalle VARCHAR(40) NOT NULL DEFAULT '',
  KEY ix_dia_tipo (dia, tipo),
  KEY ix_vis (visitante, dia, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ajustes (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
