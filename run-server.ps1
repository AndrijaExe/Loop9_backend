$ErrorActionPreference = "Stop"

Write-Host "Starting Loop9 Backend via Docker Compose..."

docker compose up --build -d

Write-Host "Server running at http://localhost:8080/api/chat"
