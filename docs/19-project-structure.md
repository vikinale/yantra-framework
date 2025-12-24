# 19. Project Structure Best Practices

## 19.1 Separation of concerns
- System/ = framework
- App/ = application
- storage/ = runtime writable

## 19.2 Suggested app layout
```text
app/
|-- Controllers/
|-- Middleware/
|-- Services/
|-- Domain/
`-- Routes/
```

## 19.3 Naming conventions
- Controllers: *Controller
- Middleware: *Middleware
- Services: *Service
