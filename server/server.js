// server/server.js
const express = require('express');
const cors = require('cors');
const db = require('./db');
const bcrypt = require('bcrypt');
const jwt = require('jsonwebtoken');
const cookieParser = require('cookie-parser');

const JWT_SECRET = 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET';
const JWT_EXPIRES_IN = '7d';

const app = express();
app.use(cors({
  origin: 'http://localhost:3000', // adjust to your frontend origin
  credentials: true,
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
  const ok = await bcrypt.compare(password, userRow.password_hash);
  if (!ok) {
    return res.json({ success: false, message: 'Invalid email or password.' });
  }
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
app.get('/api/bookings', async (req, res) => {
  const [rows] = await db.query('SELECT * FROM bookings ORDER BY created_at DESC');
  res.json(rows);
});

app.post('/api/bookings', async (req, res) => {
  const {
    booking_reference, customer_name, customer_email, customer_phone, visit_date,
    ticket_type_id, ticket_type_name, quantity, unit_price, total_amount,
    payment_status, payment_method, qr_code_data, status
  } = req.body;

  // 1) Prevent past dates
  const today = new Date();
  today.setHours(0,0,0,0);
  const visit = new Date(visit_date + 'T00:00:00');
  if (isNaN(visit.getTime()) || visit < today) {
    return res.json({ success: false, message: 'You cannot book a date in the past.' });
  }

  // 2) Capacity check
  const [scheduleRows] = await db.query(
    'SELECT capacity FROM park_schedule WHERE visit_date = ?',
    [visit_date]
  );
  const capacity = scheduleRows.length ? scheduleRows[0].capacity : 500;

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
    `INSERT INTO bookings (booking_reference,customer_name,customer_email,customer_phone,visit_date,
      ticket_type_id,ticket_type_name,quantity,unit_price,total_amount,payment_status,payment_method,qr_code_data,status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
    [
      booking_reference, customer_name, customer_email, customer_phone, visit_date,
      ticket_type_id, ticket_type_name, quantity, unit_price, total_amount,
      payment_status || 'Pending', payment_method || 'QR Ph', qr_code_data, status || 'Active'
    ]
  );
  res.json({
    success: true,
    booking: {
      id: r.insertId,
      booking_reference, customer_name, customer_email, customer_phone, visit_date,
      ticket_type_id, ticket_type_name, quantity, unit_price, total_amount,
      payment_status: payment_status || 'Pending',
      payment_method: payment_method || 'QR Ph',
      qr_code_data, status: status || 'Active'
    }
  });
});

// RIDES
app.get('/api/rides', async (req, res) => {
  const [rows] = await db.query('SELECT * FROM rides ORDER BY created_at DESC');
  res.json(rows);
});

app.post('/api/rides', async (req, res) => {
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

app.put('/api/rides/:id', async (req, res) => {
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