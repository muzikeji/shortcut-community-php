# 捷径社区 (PHP)

基于 PHP 8.2 + SQLite 的快捷指令分享社区。

## 环境要求

- PHP >= 7.4（推荐 8.2+）
- PHP 扩展：pdo_sqlite, mbstring, json, openssl, curl, xml
- Composer
- Apache / Nginx / PHP 内置开发服务器

## 一键安装

1. 将所有文件上传至网站根目录
2. 确保 `data/` 和 `uploads/` 目录可写
3. 安装 Composer 依赖：

```bash
composer install
```

4. 访问 `https://你的域名/install.php` 完成安装

安装脚本会自动创建 SQLite 数据库、建表、并引导你创建管理员账号。

## Nginx 配置

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/php-shortcut/public;
    index index.php index.html;

    location /api/ {
        try_files $uri /index.php?$query_string;
    }

    location /install.php {
        try_files $uri /index.php?$query_string;
    }

    location / {
        try_files $uri /index.html;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
```

## Apache 配置

确保启用 `mod_rewrite`，项目已包含 `.htaccess`。

## PHP 内置服务器（开发用）

```bash
php -S 0.0.0.0:8080 -t public public/index.php
```

## 目录结构

```
php-shortcut/
├── public/            # Web 根目录
│   ├── index.php      # 前端控制器
│   ├── install.php    # 安装脚本
│   └── .htaccess      # Apache 重写规则
├── src/               # PHP 源码
│   ├── Database.php   # PDO SQLite 封装
│   ├── Auth.php       # JWT 认证
│   ├── Response.php   # JSON 响应
│   ├── PlistParser.php # 快捷指令元数据解析
│   └── routes/        # API 路由
│       ├── users.php
│       ├── shortcuts.php
│       ├── interact.php
│       ├── admin.php
│       └── settings.php
├── data/              # SQLite 数据库
├── uploads/           # 用户上传文件
├── frontend/          # 前端构建产物
├── vendor/            # Composer 依赖
├── composer.json
└── .env               # 环境配置
```

## API 路由

### 认证
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | /api/auth/register | 注册 |
| POST | /api/auth/login | 登录 |
| GET | /api/auth/me | 获取当前用户 |

### 用户
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | /api/users/{id} | 用户信息 |
| PUT | /api/users/me | 更新资料 |
| PUT | /api/users/me/password | 修改密码 |
| POST | /api/users/me/avatar | 上传头像 |

### 快捷指令
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | /api/shortcuts | 列表（分页/搜索/排序） |
| GET | /api/shortcuts/{id} | 详情 |
| POST | /api/shortcuts | 发布 |
| PATCH | /api/shortcuts/{id} | 编辑 |
| DELETE | /api/shortcuts/{id} | 删除 |
| GET | /api/shortcuts/{id}/download | 下载 |
| GET | /api/shortcuts/{id}/similar | 相似推荐 |
| GET | /api/shortcuts/{id}/versions | 版本历史 |
| POST | /api/shortcuts/{id}/versions | 发布新版本 |
| POST | /api/shortcuts/{id}/refresh | 刷新统计 |

### 互动
| 方法 | 路径 | 说明 |
|------|------|------|
| POST | /api/interact/{id}/like | 点赞/取消点赞 |
| GET | /api/interact/{id}/comments | 评论列表 |
| POST | /api/interact/{id}/comments | 发表评论 |
| DELETE | /api/interact/comments/{id} | 删除评论 |

### 管理
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | /api/admin/dashboard | 仪表盘 |
| GET | /api/admin/users | 用户管理 |
| PUT | /api/admin/users/{id}/role | 修改角色 |
| PUT | /api/admin/users/{id}/banned | 封禁/解封 |
| GET | /api/admin/shortcuts | 所有快捷指令 |

### 设置
| 方法 | 路径 | 说明 |
|------|------|------|
| GET | /api/settings | 公开设置 |
| GET | /api/settings/admin | 管理设置 |
| PUT | /api/settings | 更新设置 |
| PUT | /api/settings/site | 批量更新站点设置 |
