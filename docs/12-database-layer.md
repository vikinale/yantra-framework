# 12. Database Layer

## 12.1 Goals
- Centralize connection handling
- Provide safe query execution and parameter binding
- Avoid framework-level coupling to application directories

## 12.2 Configuration
Load DB config via System\Config and/or environment variables.

## 12.3 Prepared statements example
```php
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
```

## 12.4 Error handling
In production, avoid returning SQL details to clients. Log internally instead.

## 12.5 Security
- Always bind parameters
- Avoid dynamic table/column injection
- Use least-privilege DB credentials
