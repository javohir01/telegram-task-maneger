FROM php:8.3-cli

# Install curl for health checks
RUN apt-get update && apt-get install -y curl && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www

# Copy application files
COPY . .

# Create public directory if not exists
RUN mkdir -p public

# Create simple index.php if not exists
RUN echo '<?php echo json_encode(["message" => "API is running", "time" => date("Y-m-d H:i:s")]);' > public/index.php

# Expose port 80
EXPOSE 80

# Start PHP built-in server
CMD ["php", "-S", "0.0.0.0:80", "-t", "public"]
