<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | 下面的配置决定了浏览器跨域请求时，哪些请求可以被接受、哪些响应头会被返回。
    | 只需要把你前端实际访问的域名+端口写进 allowed_origins 即可。
    |
    */

    // ① 哪些路径会走 CORS 检查
    'paths' => [
        'api/*',                 // 所有 API 路由
        'sanctum/csrf-cookie',   // Sanctum 获取 CSRF Cookie 时也需要 CORS
    ],

    // ② 允许的 HTTP 方法（* 表示全部）
    'allowed_methods' => ['*'],

    // ③ 允许跨域的来源（origin）。这里必须写完整的协议+域名+端口
    'allowed_origins' => [
        'http://vue.lara.test:5777',   // 你的 Vue‑vben‑admin 开发服务器
        'http://localhost:5777',      // 方便本机直接使用 localhost 测试
        'http://localhost:5173',       // uni-app 本地开发服务器
        'http://localhost:8080',      // HBuilder 最常用端口
        'http://127.0.0.1:8080',
        'http://localhost:8081',
        'http://127.0.0.1:8081',      // HBuilderX 以 127.0.0.1 打开时使用
        'https://admin.saololi.site',
        'https://pcr.saololi.site',
        'https://test.saololi.site',
    ],

    // ④ 允许的请求头（前端可自行添加的自定义 Header）
    'allowed_headers' => ['*'],

    // ⑤ 需要暴露给前端的响应头（一般留空即可）
    'exposed_headers' => [],

    // ⑥ 预检（OPTIONS）请求的缓存时间（秒），0 表示不缓存
    'max_age' => 0,

    // ⑦ 是否允许携带 Cookie、Authorization 等凭证
    'supports_credentials' => true,

];
