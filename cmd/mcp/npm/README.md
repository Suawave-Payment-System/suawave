# @suawave/mcp

Model Context Protocol server for the Suawave API. This package is a launcher: on install it downloads the prebuilt `suawave-mcp` binary for your platform from the project's GitHub release and checks its SHA-256 against the release `SHA256SUMS`.

Supported: macOS, Linux (x64, arm64) and Windows (x64).

## Use

```
npx -y @suawave/mcp
```

MCP client configuration:

```json
{
  "mcpServers": {
    "suawave": {
      "command": "npx",
      "args": ["-y", "@suawave/mcp"],
      "env": { "SUAWAVE_MCP_BASE_URL": "https://api.suawave.com" }
    }
  }
}
```

The public tools need no key. Set `SUAWAVE_MCP_KEY` to a merchant API key to enable the merchant tools. Set `SUAWAVE_MCP_DOCS_DIR` to a directory with the API documentation to serve it as resources.

## Environment

| Variable | Meaning |
|---|---|
| `SUAWAVE_MCP_BASE_URL` | API base URL, default `https://api.suawave.com`. |
| `SUAWAVE_MCP_KEY` | Merchant API key, enables merchant tools. |
| `SUAWAVE_MCP_DOCS_DIR` | Directory holding `api/` documentation. |
| `SUAWAVE_MCP_BIN` | Use this binary instead of the downloaded one (skips the download). |
| `SUAWAVE_MCP_SKIP_DOWNLOAD` | Skip the download on install. |
| `SUAWAVE_MCP_RELEASE_URL` | Download from this release URL instead of GitHub. |
