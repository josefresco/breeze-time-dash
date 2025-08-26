# Breeze Time Tracker Dashboard

A beautiful, real-time dashboard for tracking time and earnings from Breeze PM. Features animated backgrounds, leaderboards, and comprehensive analytics.

## Features

- 🕐 **Real-time Session Tracking** - See active timers at a glance
- 💰 **Earnings Visualization** - Track daily, weekly, and monthly earnings
- 🏆 **Champion Leaderboard** - Gamified daily competition
- 📊 **Project Analytics** - Top projects by time and earnings
- 🌈 **Dynamic Backgrounds** - Color-coded progress levels
- ⚡ **Performance Optimized** - Tab visibility detection and configurable refresh rates
- 📱 **Responsive Design** - Works on desktop and mobile

## Setup

### 🚀 Quick Deploy to Netlify (Recommended)

1. **Fork this repository** on GitHub
2. **Connect to Netlify**:
   - Go to [netlify.com](https://netlify.com)
   - Click "Add new site" → "Import an existing project"
   - Connect your GitHub account and select your forked repository
3. **Configure Environment Variables** in Netlify dashboard:
   ```
   BREEZE_API_KEY = your_actual_api_key_here
   HOURLY_RATE = 115
   EXCLUDED_USERS = admin,manager
   DAILY_INCREMENT = 92
   MAX_LEVEL = 20
   RAINBOW_THRESHOLD = 1500
   MONTHLY_MULTIPLIER = 5
   API_TIMEOUT = 10
   ```
4. **Deploy** - Netlify will automatically build and deploy your site!

### 📋 Get your Breeze PM API key:
- Log into your Breeze account
- Go to Settings → Integrations  
- Generate a new API token

### 🛠️ Local Development

1. **Clone the repository**
   ```bash
   git clone https://github.com/josefresco/breeze-time-dash.git
   cd breeze-time-dash
   ```

2. **Install Netlify CLI** (optional)
   ```bash
   npm install -g netlify-cli
   ```

3. **Set up environment variables**
   Create `.env` file:
   ```bash
   BREEZE_API_KEY=your_actual_api_key_here
   HOURLY_RATE=115
   EXCLUDED_USERS=admin,manager
   ```

4. **Run locally**
   ```bash
   netlify dev
   ```
   Opens at `http://localhost:8888`

## Deployment

### ✅ Netlify (Recommended)
- **Free hosting** with generous limits
- **Automatic deploys** from GitHub
- **Serverless functions** for secure API calls
- **Custom domains** with free SSL
- **Environment variables** for secure configuration

### Alternative Options
- **Vercel**: Similar to Netlify with serverless functions
- **Railway.app**: Full-stack deployment with Git integration
- **Any static host**: For frontend-only deployment (requires CORS setup)

## Configuration Options

### Basic Settings
| Setting | Default | Description |
|---------|---------|-------------|
| `breeze_api_key` | - | Your Breeze PM API token (required) |
| `hourly_rate` | 115 | Your billing rate per hour |
| `excluded_users` | ['admin'] | Users to exclude from leaderboard (case insensitive) |
| `api_timeout` | 10 | API request timeout in seconds |

### Progress Level System
| Setting | Default | Description |
|---------|---------|-------------|
| `daily_increment` | 92 | Dollar amount per progress level |
| `max_level` | 20 | Maximum number of progress levels |
| `rainbow_threshold` | 1500 | Earnings that trigger rainbow background (0 = disabled) |
| `monthly_multiplier` | 5 | Multiplier for monthly level calculations |
| `level_descriptions` | {} | Custom names for levels (e.g., `{1: 'Beginner', 10: 'Pro'}`) |

### Customizing Your Progress System

You can completely customize the progress levels to match your goals:

```php
'progress_levels' => [
    'daily_increment' => 100,    // $100 per level instead of $92
    'max_level' => 15,           // 15 levels instead of 20
    'rainbow_threshold' => 1200, // Rainbow at $1,200 instead of $1,500
    'monthly_multiplier' => 4,   // 4x daily for monthly (4 work days/week)
    'level_descriptions' => [
        1 => 'Getting Started',
        5 => 'Warming Up', 
        10 => 'In The Zone',
        15 => 'Legendary!'
    ]
]
```

This creates a system where:
- Level 1: $0-$100, Level 2: $100-$200, etc.
- Maximum of 15 levels (Level 15 = $1,500)
- Rainbow background at $1,200
- Monthly levels calculated as daily × 4
- Custom tooltips on hover for special levels

## Dashboard Features

### Progress Levels
The dashboard uses a configurable level system based on daily earnings:
- **Levels 1-N**: Progressive color gradient from brown to bright green
- **Rainbow Mode**: Special animation when earnings exceed your rainbow threshold
- **Fully Customizable**: Set your own level increments, maximum levels, and thresholds

### Performance Settings
- **Update Interval**: 15s, 30s, 1min, or 2min
- **Animations**: Toggle rainbow effects and bouncing
- **Compact Mode**: Optimized for smaller screens
- **Auto-pause**: Reduces CPU usage when tab is hidden

## Security

- API keys are stored in `config.php` (excluded from git)
- No sensitive data is committed to the repository
- Configuration template provided for easy setup

## Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## License

MIT License - feel free to use and modify as needed!