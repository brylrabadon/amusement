const mysql = require('mysql2/promise');

const pool = mysql.createPool({
  host: 'localhost',
  user: 'root',
  password: '12345', // ← change this
  database: 'amusepark',
  waitForConnections: true,
  connectionLimit: 10
});

module.exports = pool;