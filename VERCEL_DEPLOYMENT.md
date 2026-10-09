# Deploying Laravel 13 Backend to Vercel (Singapore - `sin1`)

This guide walks you through deploying the Laravel 13 backend to Vercel Serverless Functions in the **Singapore (`sin1`)** region with low-latency connection to Neon PostgreSQL (`ap-southeast-1`).

---

## 1. Architecture & Files Added

- **[`vercel.json`](file:///c:/Users/arnob/Music/drive%20app/backend/vercel.json)**:
  - Configures execution region explicitly to **Singapore (`sin1`)** (`"regions": ["sin1"]`).
  - Sets runtime to `vercel-php@0.8.0` (PHP 8.4 engine).
  - Routes incoming traffic (`/api/*`, `/up`, etc.) into `api/index.php`.
  - Maps serverless cache and logging environment variables.
- **[`api/index.php`](file:///c:/Users/arnob/Music/drive%20app/backend/api/index.php)**:
  - Vercel Serverless entrypoint.
  - Automatically provisions ephemeral writable `/tmp/storage` directories (`framework/views`, `framework/cache`, `framework/sessions`, `logs`, `bootstrap/cache`) so Laravel runs seamlessly on Vercel's read-only filesystem.
- **[`.vercelignore`](file:///c:/Users/arnob/Music/drive%20app/backend/.vercelignore)**:
  - Excludes local `/vendor`, `.env`, tests, and Docker assets to keep the upload bundle fast and lightweight.
- **[`.env.vercel.example`](file:///c:/Users/arnob/Music/drive%20app/backend/.env.vercel.example)**:
  - Ready-to-copy production environment variables for Vercel Project Settings.

---

## 2. Deployment Steps

### Option A: Via Vercel Web Dashboard (Recommended)

1. **Push Changes to GitHub**:
   ```bash
   git add .
   git commit -m "feat: configure backend for Vercel deployment in Singapore (sin1)"
   git push origin main
   ```

2. **Import into Vercel**:
   - Go to [vercel.com/new](https://vercel.com/new).
   - Select your repository (`POS-Backend`).
   - If the repository has a subfolder, set **Root Directory** to `backend` (or leave as `./` if the repo contains only the backend).
   - Set **Framework Preset** to **Other**.

3. **Configure Environment Variables**:
   Copy the variables from [`.env.vercel.example`](file:///c:/Users/arnob/Music/drive%20app/backend/.env.vercel.example) into **Environment Variables**:
   - `APP_NAME` = `"Smart Account POS API"`
   - `APP_ENV` = `production`
   - `APP_DEBUG` = `false`
   - `APP_KEY` = `base64:IqcGJdQPXFo2D9Dj5Gk1MDLERw7pXriU04YbDdOpwA4=`
   - `APP_URL` = `https://<your-vercel-app>.vercel.app`
   - `FRONTEND_URL` = `https://<your-frontend-domain>.vercel.app` (or `*`)
   - `DB_CONNECTION` = `pgsql`
   - `DB_HOST` = `ep-small-sea-b3617j9t-pooler.c-4.ap-southeast-1.aws.neon.tech`
   - `DB_PORT` = `5432`
   - `DB_DATABASE` = `neondb`
   - `DB_USERNAME` = `neondb_owner`
   - `DB_PASSWORD` = `<your_neon_password>`
   - `DB_SSLMODE` = `require`
   - `DB_URL` = `postgresql://neondb_owner:<your_neon_password>@ep-small-sea-b3617j9t-pooler.c-4.ap-southeast-1.aws.neon.tech/neondb?sslmode=require`
   - `JWT_SECRET` = `e4c5929a62bf928430f5b034905d20f9ae1eb8379ea9adef592fd39056cd2493`
   - `JWT_ENCRYPTION_KEY` = `15d52449913afb7b1f8dbacb85f7c8238d3b5243415d0009c1ad4d05ad561254`
   - `FORCE_HTTPS` = `true`
   - `TLS_HSTS_ENABLED` = `true`

4. **Click Deploy**:
   Vercel will trigger the build using `vercel-php`, package dependencies, and deploy the function in Singapore (`sin1`).

---

### Option B: Via Vercel CLI

```bash
cd backend
npm i -g vercel
vercel login
vercel --prod
```

When prompted:
- Set up and deploy: **Yes**
- Which scope: *(Select your personal or team account)*
- Link to existing project: **No**
- Project name: `pos-backend` (or your choice)
- In which directory is your code located: `./`

---

## 3. Post-Deployment Verification

### 1. Test Health Check Endpoint
```bash
curl https://<your-vercel-app>.vercel.app/api/v1/health
```

Expected response:
```json
{
  "success": true,
  "message": "System is operational.",
  "data": {
    "status": "healthy",
    "environment": "production",
    "php_version": "8.4.x",
    "framework": "Laravel 13.x",
    "database": {
      "connection": "pgsql",
      "status": "healthy",
      "latency": "2.4ms"
    }
  }
}
```

### 2. Verify Region
Check your Vercel Function logs in the dashboard under **Logs** -> **Function**:
The execution region badge will display **`sin1` (Singapore)**.
