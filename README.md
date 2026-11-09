# PCR API

**PCR 游戏数据与作业管理后端 API**

[简体中文](#简体中文) | [English](#english)

---

## 简体中文

### 项目简介

PCR API 是一个基于 Laravel 开发的 Princess Connect! Re:Dive（公主连结 Re:Dive）游戏数据与作业管理后端项目。

项目为用户端和管理端提供统一的 API 接口，涵盖游戏数据管理、作业管理、用户身份验证、角色池管理及公会管理等功能。

### 主要功能

- **用户系统**：用户注册、登录、验证码、访客身份及用户信息管理。
- **游戏数据**：游戏角色、Boss、角色属性及相关数据管理。
- **作业管理**：作业创建、编辑、收藏、分享及来源链接管理。
- **作业组合**：三刀、六刀组合计算与筛选，支持角色冲突处理及借用逻辑。
- **角色池管理**：用户角色池、账号管理及角色配置。
- **公会系统**：公会信息、成员及相关管理功能。
- **游戏统计**：抽卡数据统计及其他游戏相关统计功能。
- **管理后台**：管理员认证、动态菜单、角色权限及业务数据管理。

### 技术栈

| 技术 | 版本或说明 |
|---|---|
| PHP | 8.3+ |
| Laravel | 13 |
| MySQL | 8.0+ |
| Laravel Sanctum | 身份认证 |
| Composer | PHP 依赖管理 |
| Vite | 前端资源构建 |

### 项目结构

```text
pcr-api/
├── app/                # 应用业务代码
├── bootstrap/          # 框架启动配置
├── config/             # 配置文件
├── database/           # 数据库迁移、填充及数据文件
├── public/             # Web 入口及公开资源
├── resources/          # 前端资源
├── routes/             # API 路由
├── storage/            # 日志及运行时文件
├── tests/              # 自动化测试
├── .env.example        # 环境配置示例
├── artisan             # Laravel 命令行入口
├── composer.json       # PHP 依赖配置
└── README.md
```

### 环境要求

- PHP 8.3 或更高版本
- Composer
- MySQL 8.0 或更高版本
- Node.js 和 npm（用于前端资源构建）

请根据项目依赖安装 PHP 扩展，具体要求以 Composer 安装结果为准。

### 安装与运行

**1. 克隆项目**

```bash
git clone https://github.com/SakuraNymph/pcr-api.git
cd pcr-api
```

**2. 安装 PHP 依赖**

```bash
composer install
```

**3. 创建环境配置**

```bash
cp .env.example .env
```

Windows PowerShell 用户可以使用：

```powershell
Copy-Item .env.example .env
```

**4. 配置数据库**

修改 `.env` 中的数据库连接信息，并提前创建对应的 MySQL 数据库。

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pcr
DB_USERNAME=root
DB_PASSWORD=
```

请根据自己的实际环境填写数据库名称、用户名和密码。

**5. 生成应用密钥**

```bash
php artisan key:generate
```

**6. 执行数据库迁移**

```bash
php artisan migrate
```

**7. 安装前端依赖并构建资源**

```bash
npm install
npm run build
```

**8. 启动开发服务器**

```bash
php artisan serve
```

默认访问地址：

```text
http://127.0.0.1:8000
```

### 关联项目

| 项目 | GitHub |
|---|---|
| 用户端 | [pcr-user](https://github.com/SakuraNymph/pcr-user) |
| 管理端 | [pcr-admin](https://github.com/SakuraNymph/pcr-admin) |
| 后端 API | [pcr-api](https://github.com/SakuraNymph/pcr-api) |

### 说明

- 本项目目前以 API 后端为主，不包含用户端和管理端的完整前端代码。
- `.env.example` 仅提供基础配置示例，实际运行时需要根据环境自行配置。
- 游戏图片等资源文件不包含在本仓库中。
- 数据库初始化所需的其他数据，请以项目实际提供的迁移文件和数据文件为准。

### 许可证

本项目采用 [MIT License](LICENSE) 开源许可证。

---

## English

### Introduction

PCR API is a backend project built with Laravel for managing game data and team compositions in Princess Connect! Re:Dive.

It provides unified API services for the user client and administration panel, including game data management, team management, user authentication, character pools, and guild features.

### Features

- **User System**: Registration, login, verification codes, visitor identity, and user management.
- **Game Data**: Character, boss, and game-related data management.
- **Team Management**: Create, edit, bookmark, share, and manage team compositions.
- **Team Combinations**: Three-attack and six-attack combinations, including character conflict handling and borrowing logic.
- **Character Pools**: User character pools, account management, and character configuration.
- **Guild System**: Guild information, members, and related management features.
- **Game Statistics**: Gacha statistics and other game-related statistics.
- **Administration**: Admin authentication, dynamic menus, role-based permissions, and data management.

### Tech Stack

- PHP 8.3+
- Laravel 13
- MySQL 8.0+
- Laravel Sanctum
- Composer
- Vite

### Requirements

- PHP 8.3 or later
- Composer
- MySQL 8.0 or later
- Node.js and npm for frontend asset building

Install the PHP extensions required by the project dependencies.

### Installation

**1. Clone the repository**

```bash
git clone https://github.com/SakuraNymph/pcr-api.git
cd pcr-api
```

**2. Install PHP dependencies**

```bash
composer install
```

**3. Create the environment file**

```bash
cp .env.example .env
```

**4. Configure the database**

Update the database settings in `.env` and create the corresponding MySQL database.

**5. Generate the application key**

```bash
php artisan key:generate
```

**6. Run database migrations**

```bash
php artisan migrate
```

**7. Install and build frontend assets**

```bash
npm install
npm run build
```

**8. Start the development server**

```bash
php artisan serve
```

The default development address is:

```text
http://127.0.0.1:8000
```

### Related Projects

- User Client: [pcr-user](https://github.com/SakuraNymph/pcr-user)
- Admin Panel: [pcr-admin](https://github.com/SakuraNymph/pcr-admin)
- Backend API: [pcr-api](https://github.com/SakuraNymph/pcr-api)

### Notes

- This repository primarily contains the backend API. The complete user client and administration frontend are maintained separately.
- `.env.example` provides basic configuration examples. Configure your own environment before running the application.
- Game image assets are not included in this repository.
- Refer to the provided migrations and data files for database initialization requirements.

### License

This project is licensed under the [MIT License](LICENSE).