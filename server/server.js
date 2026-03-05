// server/server.js
const express = require('express');
const cors = require('cors');
const db = require('./db');
const bcrypt = require('bcrypt');
const jwt = require('jsonwebtoken');
const cookieParser = require('cookie-parser');
const crypto = require('crypto');
const dotenv = require('dotenv');

dotenv.config();

const PORT = Number(process.env.PORT || 3001);
const JWT_SECRET = process.env.JWT_SECRET || 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET';
const JWT_EXPIRES_IN = '7d';

const app = express();
app.use(cors({
  origin: (origin, cb) => cb(null, true),
  credentials: true,
  methods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization'],
}));
app.use(express.json());
app.use(cookieParser());
app.use(express.static('public')); // for future image uploads if you switch to disk storage

function signToken(user) {
  return jwt.sign(
    { id: user.id, role: user.role, full_name: user.full_name, email: user.email },
    JWT_SECRET,
    { expiresIn: JWT_EXPIRES_IN }
  );
}

async function authMiddleware(req, res, next) {
  try {
    const header = req.headers.authorization || '';
    const token = header.startsWith('Bearer ') ? header.slice(7) : null;
    if (!token) return res.status(401).json({ success: false, message: 'Unauthorized' });
    const payload = jwt.verify(token, JWT_SECRET);
    req.user = payload;
    next();
  } catch (err) {
    return res.status(401).json({ success: false, message: 'Invalid token' });
  }
}

function requireRole(role) {
  return (req, res, next) => {
    if (!req.user || req.user.role !== role) {
      return res.status(403).json({ success: false, message: 'Forbidden' });
    }
    next();
  };
}

function isSha256Hex(str) {
  return typeof str === 'string' && /^[a-f0-9]{64}$/i.test(str);
}

async function ensureSchema() {
  // rides.price (older schema may not have it)
  const [priceCols] = await db.query(
    `SELECT COLUMN_NAME
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rides' AND COLUMN_NAME = 'price'`
  );
  if (!priceCols.length) {
    await db.query('ALTER TABLE rides ADD COLUMN price DECIMAL(10,2) NOT NULL DEFAULT 0');
  }

  // park_schedule (booking capacity table) - optional feature
  await db.query(
    `CREATE TABLE IF NOT EXISTS park_schedule (
      visit_date DATE PRIMARY KEY,
      capacity INT NOT NULL DEFAULT 500,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )`
  );
}

async function ensureAdminSeed() {
  const email = 'admin@amusepark.com';
  const [rows] = await db.query('SELECT id FROM users WHERE email = ?', [email]);
  if (rows.length) return;
  const hash = await bcrypt.hash('Admin1234', 10);
  await db.query(
    'INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?,?,?,?,?)',
    ['Admin', email, '', hash, 'admin']
  );
}

// ─── AUTH ─────────────────────────────────────────────
app.post('/api/auth/register', async (req, res) => {
  const { full_name, email, phone, password } = req.body;
  if (!full_name || !email || !password) {
    return res.json({ success: false, message: 'Missing required fields.' });
  }
  const [existing] = await db.query('SELECT id FROM users WHERE email = ?', [email]);
  if (existing.length) {
    return res.json({ success: false, message: 'Email is already registered.' });
  }
  const hash = await bcrypt.hash(password, 10);
  const [result] = await db.query(
    'INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?,?,?,?,?)',
    [full_name, email, phone || '', hash, 'customer']
  );
  const user = { id: result.insertId, full_name, email, phone: phone || '', role: 'customer' };
  const token = signToken(user);
  res.json({ success: true, token, user });
});

app.post('/api/auth/login', async (req, res) => {
  const { email, password } = req.body;
  if (!email || !password) {
    return res.json({ success: false, message: 'Missing email or password.' });
  }
  const [rows] = await db.query('SELECT * FROM users WHERE email = ?', [email]);
  if (!rows.length) {
    return res.json({ success: false, message: 'Invalid email or password.' });
  }
  const userRow = rows[0];
  const stored = userRow.password_hash || '';

  let ok = false;
  if (stored.startsWith('$2')) {
    ok = await bcrypt.compare(password, stored);
  } else if (isSha256Hex(stored)) {
    const sha = crypto.createHash('sha256').update(password).digest('hex');
    ok = sha.toLowerCase() === stored.toLowerCase();

    // Upgrade legacy SHA256 hashes to bcrypt on successful login
    if (ok) {
      const newHash = await bcrypt.hash(password, 10);
      await db.query('UPDATE users SET password_hash = ? WHERE id = ?', [newHash, userRow.id]);
    }
  }

  if (!ok) return res.json({ success: false, message: 'Invalid email or password.' });

  const user = {
    id: userRow.id,
    full_name: userRow.full_name,
    email: userRow.email,
    phone: userRow.phone,
    role: userRow.role,
  };
  const token = signToken(user);
  res.json({ success: true, token, user });
});

app.get('/api/auth/me', authMiddleware, async (req, res) => {
  res.json({ success: true, user: req.user });
});

// ─── BOOKINGS ─────────────────────────────────────────
app.get('/api/bookings', authMiddleware, requireRole('admin'), async (req, res) => {
  const [rows] = await db.query('SELECT * FROM bookings ORDER BY created_at DESC');
  res.json(rows);
});

app.get('/api/bookings/my', authMiddleware, async (req, res) => {
  const [rows] = await db.query(
    'SELECT * FROM bookings WHERE customer_email = ? ORDER BY created_at DESC',
    [req.user.email]
  );
  res.json(rows);
});

app.post('/api/bookings', authMiddleware, async (req, res) => {
  const {
    booking_reference, customer_name, customer_email, customer_phone, visit_date,
    ticket_type_id, ticket_type_name, quantity, unit_price, total_amount,
    payment_status, payment_method, qr_code_data, status
  } = req.body;

  const userId = req.user.id;
  const safeEmail = req.user.email;
  const safeName = customer_name || req.user.full_name;

  // 1) Prevent past dates
  const today = new Date();
  today.setHours(0,0,0,0);
  const visit = new Date(visit_date + 'T00:00:00');
  if (isNaN(visit.getTime()) || visit < today) {
    return res.json({ success: false, message: 'You cannot book a date in the past.' });
  }

  // 2) Capacity check
  let capacity = 500;
  try {
    const [scheduleRows] = await db.query(
      'SELECT capacity FROM park_schedule WHERE visit_date = ?',
      [visit_date]
    );
    capacity = scheduleRows.length ? scheduleRows[0].capacity : 500;
  } catch (_) {
    capacity = 500;
  }

  const [aggRows] = await db.query(
    'SELECT COALESCE(SUM(quantity),0) AS used FROM bookings WHERE visit_date = ? AND payment_status IN ("Pending","Paid")',
    [visit_date]
  );
  const used = aggRows[0].used || 0;
  if (used + quantity > capacity) {
    return res.json({
      success: false,
      message: `Selected date is fully booked. Only ${Math.max(capacity - used, 0)} slots left.`,
    });
  }

  // 3) Proceed with insert
  const [r] = await db.query(
    `INSERT INTO bookings (booking_reference,user_id,customer_name,customer_email,customer_phone,visit_date,
      ticket_type_id,ticket_type_name,quantity,unit_price,total_amount,payment_status,payment_method,qr_code_data,status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      booking_reference, userId, safeName, safeEmail, customer_phone || '', visit_date,
      ticket_type_id, ticket_type_name, quantity, unit_price, total_amount,
      payment_status || 'Pending', payment_method || 'QR Ph', qr_code_data, status || 'Active'
    ]
  );
  res.json({
    success: true,
    booking: {
      id: r.insertId,
      booking_reference,
      user_id: userId,
      customer_name: safeName,
      customer_email: safeEmail,
      customer_phone: customer_phone || '',
      visit_date,
      ticket_type_id, ticket_type_name, quantity, unit_price, total_amount,
      payment_status: payment_status || 'Pending',
      payment_method: payment_method || 'QR Ph',
      qr_code_data, status: status || 'Active'
    }
  });
});

// Update booking (payment status, status, etc.)
app.put('/api/bookings/:id', authMiddleware, async (req, res) => {
  const [rows] = await db.query('SELECT id, user_id, customer_email FROM bookings WHERE id = ?', [req.params.id]);
  if (!rows.length) return res.status(404).json({ success: false, message: 'Booking not found.' });
  const b = rows[0];

  const isOwner = (b.user_id != null && Number(b.user_id) === Number(req.user.id)) || (b.customer_email === req.user.email);
  const isAdmin = req.user.role === 'admin';
  if (!isAdmin && !isOwner) return res.status(403).json({ success: false, message: 'Forbidden' });

  const allowed = isAdmin
    ? ['payment_status', 'status', 'payment_method', 'payment_reference']
    : ['payment_status', 'payment_method', 'payment_reference'];

  const updates = [];
  const params = [];
  for (const key of allowed) {
    if (req.body[key] !== undefined) {
      updates.push(`${key} = ?`);
      params.push(req.body[key]);
    }
  }
  if (!updates.length) {
    return res.json({ success: false, message: 'No fields to update.' });
  }
  params.push(req.params.id);
  await db.query(`UPDATE bookings SET ${updates.join(', ')} WHERE id = ?`, params);
  res.json({ success: true });
});

// ─── RIDES ─────────────────────────────────────────────
app.get('/api/rides', async (req, res) => {
  const [rows] = await db.query('SELECT * FROM rides ORDER BY created_at DESC');
  res.json(rows);
});

app.post('/api/rides', authMiddleware, requireRole('admin'), async (req, res) => {
  const {
    name, description, category, duration_minutes,
    min_height_cm, max_capacity, price,
    status, image_url, is_featured
  } = req.body;
  const [r] = await db.query(
    `INSERT INTO rides (name,description,category,duration_minutes,min_height_cm,max_capacity,price,status,image_url,is_featured)
     VALUES (?,?,?,?,?,?,?,?,?,?)`,
    [
      name, description, category,
      duration_minutes, min_height_cm, max_capacity,
      price || 0, status || 'Open', image_url, is_featured || false
    ]
  );
  res.json({ id: r.insertId, ...req.body });
});

app.put('/api/rides/:id', authMiddleware, requireRole('admin'), async (req, res) => {
  const {
    name, description, category, duration_minutes,
    min_height_cm, max_capacity, price,
    status, image_url, is_featured
  } = req.body;
  await db.query(
    `UPDATE rides
     SET name=?,description=?,category=?,duration_minutes=?,min_height_cm=?,max_capacity=?,price=?,status=?,image_url=?,is_featured=?
     WHERE id=?`,
    [
      name, description, category, duration_minutes,
      min_height_cm, max_capacity, price || 0,
      status, image_url, is_featured, req.params.id
    ]
  );
  res.json({ success: true });
});

app.delete('/api/rides/:id', authMiddleware, requireRole('admin'), async (req, res) => {
  await db.query('DELETE FROM rides WHERE id = ?', [req.params.id]);
  res.json({ success: true });
});

// ─── TICKET TYPES ─────────────────────────────────────
app.get('/api/ticket-types', async (req, res) => {
  const [rows] = await db.query('SELECT * FROM ticket_types ORDER BY price ASC');
  res.json(rows);
});

app.post('/api/ticket-types', authMiddleware, requireRole('admin'), async (req, res) => {
  const { name, description, category, price, max_rides, is_active } = req.body;
  if (!name || price == null) {
    return res.json({ success: false, message: 'Name and price are required.' });
  }
  const [r] = await db.query(
    `INSERT INTO ticket_types (name, description, category, price, max_rides, is_active)
     VALUES (?,?,?,?,?,?)`,
    [
      name,
      description || '',
      category || 'Single Day',
      Number(price),
      max_rides != null ? Number(max_rides) : null,
      is_active ? 1 : 0
    ]
  );
  res.json({
    success: true,
    ticket_type: {
      id: r.insertId,
      name,
      description: description || '',
      category: category || 'Single Day',
      price: Number(price),
      max_rides: max_rides != null ? Number(max_rides) : null,
      is_active: !!is_active
    }
  });
});

app.put('/api/ticket-types/:id', authMiddleware, requireRole('admin'), async (req, res) => {
  const allowed = ['name', 'description', 'category', 'price', 'max_rides', 'is_active'];
  const updates = [];
  const params = [];
  for (const key of allowed) {
    if (req.body[key] !== undefined) {
      updates.push(`${key} = ?`);
      if (key === 'price') {
        params.push(Number(req.body[key]));
      } else if (key === 'max_rides') {
        params.push(req.body[key] != null ? Number(req.body[key]) : null);
      } else if (key === 'is_active') {
        params.push(req.body[key] ? 1 : 0);
      } else {
        params.push(req.body[key]);
      }
    }
  }
  if (!updates.length) {
    return res.json({ success: false, message: 'No fields to update.' });
  }
  params.push(req.params.id);
  await db.query(`UPDATE ticket_types SET ${updates.join(', ')} WHERE id = ?`, params);
  res.json({ success: true });
});

app.delete('/api/ticket-types/:id', authMiddleware, requireRole('admin'), async (req, res) => {
  await db.query('DELETE FROM ticket_types WHERE id = ?', [req.params.id]);
  res.json({ success: true });
});

async function start() {
  await ensureSchema();
  await ensureAdminSeed();
  app.listen(PORT, () => console.log(`API running on http://localhost:${PORT}`));
}

start().catch((err) => {
  console.error('Failed to start server:', err);
  process.exit(1);
});