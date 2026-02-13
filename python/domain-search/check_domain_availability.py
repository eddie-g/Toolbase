#!/usr/bin/env python3
"""
Domain Availability Checker
Check domain availability across multiple TLDs via WHOIS lookups.
"""

import socket
import subprocess
import sys
import argparse
import re
import json
import requests
from concurrent.futures import ThreadPoolExecutor, as_completed


# WHOIS servers per TLD
WHOIS_SERVERS = {
    ".com": "whois.verisign-grs.com",
    ".ai": "whois.nic.ai",
    ".net": "whois.verisign-grs.com",
    ".org": "whois.pir.org",
}
IANA_WHOIS_SERVER = "whois.iana.org"
WHOIS_SERVER_CACHE: dict[str, str | None] = {}

# Patterns that indicate a domain is NOT registered (i.e., available)
AVAILABLE_PATTERNS = [
    "no match for",
    "not found",
    "no entries found",
    "no data found",
    "domain not found",
    "no object found",
    "nothing found",
    "status: free",
    "status: available",
    "is available for",
]

# Patterns that indicate a domain is for sale
FOR_SALE_PATTERNS = [
    "for sale",
    "available for purchase",
    "buy this domain",
    "premium domain",
    "inquire at",
    "make an offer",
    "domain marketplace",
    "afternic",
    "sedo",
    "godaddy auctions",
    "domain for sale",
    "purchase this domain",
    "buy now",
    "domain broker",
    "this domain may be for sale",
]

# URL patterns that indicate marketplace/parking pages
MARKETPLACE_URLS = [
    "afternic.com",
    "sedo.com",
    "atom.com",
    "dan.com",
    "squadhelp.com",
    "uniqdomains.com",
    "parkingcrew.com",
    "godaddy.com/domainsearch",
    "domainmarket.com",
    "brandbucket.com",
    "brandpa.com",
]

# HTML content patterns for for-sale pages
FOR_SALE_HTML_PATTERNS = [
    "buy this domain",
    "domain is for sale",
    "inquire about this domain",
    "make an offer",
    "purchase this domain",
    "domain name is listed",
    "premium domain",
    "this domain may be for sale",
    "buy now",
    "add to cart",
    "afternic.com/forsale",
    "sedo.com/search",
    "atom.com/name",
    "dan.com/buy-domain",
]

# Skip HTTP checks for these well-known domains (obviously not for sale)
SKIP_HTTP_CHECK = [
    "google", "facebook", "twitter", "amazon", "apple", "microsoft",
    "youtube", "linkedin", "instagram", "reddit", "netflix", "ebay",
    "paypal", "wikipedia", "yahoo", "adobe", "salesforce", "zoom",
    "spotify", "dropbox", "github", "stackoverflow", "medium",
]


def whois_query(domain: str, server: str, timeout: int = 15) -> str:
    """Send a raw WHOIS query to the specified server."""
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(timeout)
        sock.connect((server, 43))
        sock.sendall((domain + "\r\n").encode("utf-8"))

        response = b""
        while True:
            chunk = sock.recv(4096)
            if not chunk:
                break
            response += chunk
        sock.close()
        return response.decode("utf-8", errors="replace")
    except Exception as e:
        return f"ERROR: {e}"


def resolve_whois_server(tld: str) -> str | None:
    """Resolve WHOIS server for a TLD (e.g. '.io')."""
    if tld in WHOIS_SERVERS:
        return WHOIS_SERVERS[tld]

    if tld in WHOIS_SERVER_CACHE:
        return WHOIS_SERVER_CACHE[tld]

    response = whois_query(tld.lstrip("."), IANA_WHOIS_SERVER, timeout=10)
    if response.startswith("ERROR:"):
        WHOIS_SERVER_CACHE[tld] = None
        return None

    match = re.search(r"^whois:\s*(\S+)", response, flags=re.IGNORECASE | re.MULTILINE)
    if not match:
        WHOIS_SERVER_CACHE[tld] = None
        return None

    server = match.group(1).strip()
    if not server or server.lower() in {"none", "not available"}:
        WHOIS_SERVER_CACHE[tld] = None
        return None

    WHOIS_SERVER_CACHE[tld] = server
    return server


def check_http_for_sale(domain: str, timeout: int = 7) -> tuple[bool, str]:
    """
    Check if domain redirects to marketplace or contains for-sale indicators.
    Returns (is_for_sale, redirect_url).
    """
    try:
        # Try both http and https
        for protocol in ["https", "http"]:
            try:
                url = f"{protocol}://{domain}"
                response = requests.get(
                    url,
                    timeout=timeout,
                    allow_redirects=True,
                    headers={"User-Agent": "Mozilla/5.0 (compatible; DomainChecker/1.0)"}
                )
                
                final_url = response.url.lower()
                content = response.text.lower()
                
                # Check if redirected to marketplace
                for marketplace in MARKETPLACE_URLS:
                    if marketplace in final_url:
                        return (True, response.url)
                
                # Check page content for for-sale patterns
                for pattern in FOR_SALE_HTML_PATTERNS:
                    if pattern in content:
                        return (True, response.url)
                
                # If we got a successful response, domain is taken but not obviously for sale
                return (False, response.url)
                
            except (requests.exceptions.SSLError, requests.exceptions.ConnectionError):
                # Try next protocol
                continue
            except requests.exceptions.Timeout:
                # Timeout doesn't tell us much
                break
                
    except Exception:
        pass
    
    return (False, "")


def check_domain(domain: str) -> dict:
    """Check availability of a single domain."""
    tld = "." + domain.split(".")[-1]
    server = resolve_whois_server(tld)

    if not server:
        return {"domain": domain, "available": None, "for_sale": False, "error": f"Unsupported TLD: {tld}"}

    response = whois_query(domain, server)

    if response.startswith("ERROR:"):
        return {"domain": domain, "available": None, "for_sale": False, "error": response}

    response_lower = response.lower()
    available = any(pattern in response_lower for pattern in AVAILABLE_PATTERNS)
    for_sale_whois = any(pattern in response_lower for pattern in FOR_SALE_PATTERNS)

    # If domain is not available and not marked for sale in WHOIS, check HTTP
    # Skip HTTP check for well-known domains to save time
    for_sale = for_sale_whois
    base_name = domain.split(".")[0].lower()
    if not available and not for_sale_whois and base_name not in SKIP_HTTP_CHECK:
        for_sale_http, redirect_url = check_http_for_sale(domain)
        for_sale = for_sale_http

    return {"domain": domain, "available": available, "for_sale": for_sale, "error": None}


def print_result(result: dict):
    """Pretty-print a single domain check result."""
    domain = result["domain"]
    if result["error"]:
        print(f"  {'?':<3} {domain:<40} (error: {result['error']})")
    elif result["available"]:
        print(f"  {'✓':<3} {domain:<40} AVAILABLE")
    elif result.get("for_sale"):
        print(f"  {'$':<3} {domain:<40} FOR SALE")
    else:
        print(f"  {'✗':<3} {domain:<40} taken")


def expand_domains(names: list[str], tlds: list[str]) -> list[str]:
    """Expand bare names into full domain names with requested TLDs."""
    domains = []
    for name in names:
        name = name.strip().lower()
        if not name:
            continue
        # Extract base name (strip any TLD the user may have typed)
        base = name.split(".")[0]
        for tld in tlds:
            full = base + tld
            if full not in domains:
                domains.append(full)
    return domains


def interactive_mode(tlds: list[str]):
    """Run in interactive loop mode."""
    print("Domain Availability Checker (interactive mode)")
    print(f"Checking TLDs: {', '.join(tlds)}")
    print("Type domain names (without TLD), comma-separated, or 'q' to quit.\n")

    while True:
        try:
            user_input = input("Search: ").strip()
        except (EOFError, KeyboardInterrupt):
            print("\nBye!")
            break

        if user_input.lower() in ("q", "quit", "exit"):
            print("Bye!")
            break

        if not user_input:
            continue

        names = [n.strip() for n in user_input.replace(" ", ",").split(",") if n.strip()]
        domains = expand_domains(names, tlds)

        if not domains:
            continue

        print()
        with ThreadPoolExecutor(max_workers=16) as pool:
            futures = {pool.submit(check_domain, d): d for d in domains}
            results = []
            for future in as_completed(futures):
                results.append(future.result())

        # Sort results to group by base name
        results.sort(key=lambda r: r["domain"])
        for r in results:
            print_result(r)
        print()


def main():
    parser = argparse.ArgumentParser(
        description="Check domain availability for .com and .ai TLDs",
        epilog="Examples:\n"
               "  python check_domain_availability.py coolstartup myapp\n"
               "  python check_domain_availability.py -t com coolstartup\n"
               "  python check_domain_availability.py -i\n",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument(
        "names",
        nargs="*",
        help="Domain names to check (without TLD). E.g.: coolstartup myapp",
    )
    parser.add_argument(
        "-t", "--tlds",
        nargs="+",
        default=["com", "ai", "net", "org"],
        help="TLDs to check (default: com ai net org). Accepts values like com, .com, io, xyz.",
    )
    parser.add_argument(
        "-i", "--interactive",
        action="store_true",
        help="Run in interactive mode for repeated searches",
    )
    parser.add_argument(
        "--jsonl",
        action="store_true",
        help="Stream JSON lines output (one result per line)",
    )

    args = parser.parse_args()

    normalized_tlds: list[str] = []
    for raw_tld in args.tlds:
        cleaned = raw_tld.strip().lower().lstrip(".")
        if not re.fullmatch(r"[a-z0-9-]{2,63}", cleaned):
            parser.error(f"Invalid TLD: {raw_tld}")
        if cleaned not in normalized_tlds:
            normalized_tlds.append(cleaned)

    tlds = ["." + t for t in normalized_tlds]

    if args.interactive or not args.names:
        interactive_mode(tlds)
        return

    domains = expand_domains(args.names, tlds)

    if args.jsonl:
        with ThreadPoolExecutor(max_workers=16) as pool:
            futures = {pool.submit(check_domain, d): d for d in domains}
            for future in as_completed(futures):
                result = future.result()
                print(json.dumps(result, ensure_ascii=False))
                sys.stdout.flush()
        return

    print(f"\nChecking {len(domains)} domain(s)...\n")
    with ThreadPoolExecutor(max_workers=16) as pool:
        futures = {pool.submit(check_domain, d): d for d in domains}
        results = []
        for future in as_completed(futures):
            results.append(future.result())

    results.sort(key=lambda r: r["domain"])

    available_count = 0
    for r in results:
        print_result(r)
        if r["available"]:
            available_count += 1

    print(f"\n  {available_count}/{len(results)} available\n")


if __name__ == "__main__":
    main()
