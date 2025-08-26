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

1. **Clone the repository**
   ```bash
   git clone https://github.com/josefresco/get-that-money.git
   cd get-that-money
   ```

2. **Configure your API credentials**
   ```bash
   cp config.php.example config.php
   ```

3. **Edit `config.php`** with your Breeze PM API key:
   ```php
   return [
       'breeze_api_key' => 'your_actual_api_key_here',
       'hourly_rate' => 115, // Your hourly rate
       'excluded_users' => ['admin'], // Users to exclude from leaderboard
       'api_timeout' => 10
   ];
   ```

4. **Get your Breeze PM API key**:
   - Log into your Breeze account
   - Go to Settings → Integrations
   - Generate a new API token

## Deployment

### Local Development
Run with any local PHP server:
```bash
php -S localhost:8000
```

### Production
Deploy to any web server with PHP support. The dashboard will be available at your domain.

**Note**: GitHub Pages only supports static files, so the PHP API won't work there. You'll need a PHP-enabled hosting service.

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