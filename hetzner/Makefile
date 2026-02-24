.PHONY: build install lint tidy clean help

BINARY   := aiquila-hetzner
CMD_PATH := ./cmd/aiquila-hetzner
INSTALL_DIR ?= $(HOME)/.local/bin

## build: Compile the CLI binary into the current directory
build:
	go build -o $(BINARY) $(CMD_PATH)

## install: Build and install the binary to $(INSTALL_DIR)
install: build
	@mkdir -p $(INSTALL_DIR)
	cp $(BINARY) $(INSTALL_DIR)/$(BINARY)
	@echo "Installed to $(INSTALL_DIR)/$(BINARY)"

## lint: Run go vet
lint:
	go vet ./...

## tidy: Tidy and verify Go modules
tidy:
	go mod tidy
	go mod verify

## clean: Remove built binary
clean:
	rm -f $(BINARY)

## help: Show available targets
help:
	@grep -E '^## ' Makefile | sed 's/## //' | column -t -s ':'
