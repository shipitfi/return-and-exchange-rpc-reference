set dotenv-load := true

default: up

up:
    docker compose up --build --detach --wait

down:
    docker compose down

logs:
    docker compose logs --follow rpc

status:
    docker compose ps

test:
    docker compose exec rpc php artisan test --compact tests/Feature/ProviderRpcEndpointTest.php
