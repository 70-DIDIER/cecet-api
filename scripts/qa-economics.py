#!/usr/bin/env python3
"""Non-transactional CECT economic scenario checks. All parameters are assumptions;
no ledger entries are created, no merchants paid, no prices changed in production.
"""
from decimal import Decimal, ROUND_DOWN, getcontext
from json import dumps
getcontext().prec = 28
X = Decimal
CENTI = X("0.000001")
price = X("650")
seller_purchase = X("510")
merchant_options = {"cessation_610": X("610"), "sale_minus_3pct": X("650") * (X("1") - X("0.03"))}
bonuses = (X("0"), X("0.045"), X("0.05"), X("0.08"))
ticket = X("100000")
rows = []
for label, merchant_price in merchant_options.items():
    for bonus in bonuses:
        base = (ticket / price).quantize(CENTI, rounding=ROUND_DOWN)
        bonus_yem = (base * bonus).quantize(CENTI, rounding=ROUND_DOWN)
        issued = base + bonus_yem
        total_redemption = issued * merchant_price
        gross = ticket - total_redemption
        rows.append({
            "merchant_scenario": label,
            "customer_spend_xof": int(ticket),
            "customer_rate_xof_yem": str(price),
            "seller_purchase_xof_yem": str(seller_purchase),
            "bonus_pct": str(bonus * 100),
            "base_yem": str(base),
            "bonus_yem": str(bonus_yem),
            "issued_yem": str(issued),
            "merchant_redeem_xof_yem": str(merchant_price),
            "merchant_payout_xof": str(total_redemption.quantize(X("0.01"))),
            "cash_after_sale_and_redemption_xof": str(gross.quantize(X("0.01"))),
            "positive_before_opex_psp": gross > 0,
        })
    # A theoretical ceiling on bonus share before operations, PSP, float and fraud costs
    theoretical_max_bonus = ((price / merchant_price) - X(1)) * X(100)
    print(f"{label}: cashback break-even (before all costs) = {theoretical_max_bonus:.4f}%", flush=True)
assert rows[2]["issued_yem"] == "161.538460"
assert rows[2]["positive_before_opex_psp"] is True
assert rows[6]["positive_before_opex_psp"] is False
monthly_base = X("2000000")
months = 60
for label, merchant_price in merchant_options.items():
    gross_fiat_outflows_over_5yr = monthly_base * merchant_price * months
    print(f"{label}: cumulative 60-month merchant payout at 2M YEM/month WITHOUT bonus = {gross_fiat_outflows_over_5yr:,} XOF (flow, NOT working-capital requirement)")
print(dumps({"assumptions": "Every purchased and bonus YEM reaches a merchant and is fully redeemed, no added fee; not a CECT profit and loss statement.", "rows": rows}, ensure_ascii=False, indent=2))
