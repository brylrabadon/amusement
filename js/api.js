// js/api.js
const API = 'http://localhost:3001/api';

const http = {
  get: (url) => fetch(API + url, {
    headers: Auth && Auth.authHeader ? Auth.authHeader() : {},
  }).then(r => r.json()),
  post: (url, data) => fetch(API + url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...(Auth.authHeader ? Auth.authHeader() : {}) },
    body: JSON.stringify(data)
  }).then(r => r.json()),
  put: (url, data) => fetch(API + url, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', ...(Auth.authHeader ? Auth.authHeader() : {}) },
    body: JSON.stringify(data)
  }).then(r => r.json()),
  del: (url) => fetch(API + url, {
    method: 'DELETE',
    headers: Auth && Auth.authHeader ? Auth.authHeader() : {},
  }).then(r => r.json()),
};