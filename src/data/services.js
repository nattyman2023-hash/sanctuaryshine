export const services = [
  {
    id: 'domestic-cleaning',
    title: 'Domestic Cleaning',
    shortTitle: 'Domestic Cleaning',
    tagline: 'Regular home cleaning tailored to your schedule',
    description: 'Our domestic cleaning service keeps your home spotless with weekly, fortnightly, or one-off visits. Perfect for busy professionals and families across Greater Manchester who want to come home to a clean, fresh sanctuary.',
    icon: 'home',
    features: [
      'Dusting and polishing all surfaces',
      'Vacuuming and mopping all floors',
      'Bathroom and kitchen sanitisation',
      'Bedroom and living area refresh',
      'Stairs and hallway cleaning',
      'Bin emptying and replacement',
      'Door handles and light switches wiped',
      'Mirrors and glass surfaces cleaned'
    ],
    benefits: [
      'Flexible scheduling to suit your lifestyle',
      'Same friendly, vetted cleaner each visit',
      'Eco-friendly cleaning products used',
      'Fully insured with public liability cover',
      'No long-term contracts required'
    ],
    suitableFor: 'Homes, flats, apartments, and houses of all sizes',
    priceFrom: '£25 per hour',
    slug: 'domestic-cleaning'
  },
  {
    id: 'commercial-cleaning',
    title: 'Commercial Cleaning',
    shortTitle: 'Commercial Cleaning',
    tagline: 'Professional workspace cleaning for businesses',
    description: 'Keep your workplace clean, hygienic and professional with our commercial cleaning service. We work around your business hours to ensure minimal disruption while maintaining the highest standards of cleanliness.',
    icon: 'business',
    features: [
      'Office cleaning and sanitisation',
      'Retail unit maintenance',
      'Communal area cleaning',
      'Kitchen and break room hygiene',
      'Washroom sanitisation',
      'Floor care and deep cleaning',
      'Window cleaning',
      'Waste management'
    ],
    benefits: [
      'Cleaning during or outside business hours',
      'DBS-checked and trained staff',
      'COSHH-compliant cleaning products',
      'Regular quality inspections',
      'Flexible contract terms'
    ],
    suitableFor: 'Offices, retail units, communal buildings, medical clinics, schools',
    priceFrom: '£15 per hour',
    slug: 'commercial-cleaning'
  },
  {
    id: 'deep-cleaning',
    title: 'Deep Cleaning',
    shortTitle: 'Deep Cleaning',
    tagline: 'Intensive top-to-bottom cleaning for a fresh start',
    description: 'Our deep cleaning service goes far beyond a standard clean. We tackle every nook and cranny, from built-up grime in kitchens to soap scum in bathrooms, leaving your property feeling like new.',
    icon: 'cleaning_services',
    features: [
      'Kitchen deep clean including oven and extractor',
      'Bathroom deep clean including tile scrubbing',
      'Inside cupboard and drawer cleaning',
      'Behind and under furniture moved and cleaned',
      'Window frames and sills detailed',
      'Light fixtures and switches cleaned',
      'Skirting boards and door frames wiped',
      'Cobweb removal from ceilings and corners'
    ],
    benefits: [
      'One-off intensive clean for a fresh start',
      'Ideal for spring cleaning or post-renovation',
      'All equipment and products provided',
      'Thorough checklist ensures nothing is missed',
      'Satisfaction guaranteed or we re-clean'
    ],
    suitableFor: 'Homes needing a thorough refresh, post-renovation properties',
    priceFrom: '£35 per hour',
    slug: 'deep-cleaning'
  },
  {
    id: 'end-of-tenancy-cleaning',
    title: 'End of Tenancy Cleaning',
    shortTitle: 'End of Tenancy',
    tagline: 'Deposit-back guaranteed cleaning for tenants and landlords',
    description: 'Moving out? Our end of tenancy cleaning service is designed to help you get your full deposit back. We follow letting agent standards and provide a detailed checklist that inventory clerks recognise and trust.',
    icon: 'key',
    features: [
      'Full kitchen deep clean including appliances',
      'Bathroom deep clean including limescale removal',
      'All rooms vacuumed and mopped',
      'Windows and window frames cleaned',
      'Carpets professionally shampooed',
      'Walls spot-cleaned for marks',
      'All surfaces dusted and polished',
      'Garden and outdoor area tidied'
    ],
    benefits: [
      'Deposit-back guarantee included',
      'Checklist aligned with letting agent standards',
      'Available for same-day or next-day service',
      'Fully insured for your peace of mind',
      'Free re-clean if your landlord is not satisfied'
    ],
    suitableFor: 'Tenants moving out, landlords preparing for new tenants',
    priceFrom: '£80 for a 1-bedroom property',
    slug: 'end-of-tenancy-cleaning'
  },
  {
    id: 'airbnb-cleaning',
    title: 'Airbnb Cleaning',
    shortTitle: 'Airbnb Cleaning',
    tagline: 'Turnover cleaning for short-term rental properties',
    description: 'Maximise your Airbnb bookings with professional turnaround cleaning. We ensure your property is guest-ready between stays, with rapid response times and consistent quality that earns you five-star reviews.',
    icon: 'night_shelter',
    features: [
      'Full turnover clean between guests',
      'Linen change and bed making',
      'Bathroom and kitchen sanitisation',
      'Restocking of essentials if required',
      'Property inspection and damage check',
      'Quick turnaround for same-day bookings',
      'Key collection and drop-off available',
      'Welcome pack preparation'
    ],
    benefits: [
      'Fast turnaround — ready in 2-4 hours',
      'Consistent quality for 5-star reviews',
      'Flexible scheduling around your bookings',
      'Key handling and access management',
      'Monthly invoicing available for regular hosts'
    ],
    suitableFor: 'Airbnb hosts, holiday let owners, short-term rental managers',
    priceFrom: '£45 per clean',
    slug: 'airbnb-cleaning'
  },
  {
    id: 'office-cleaning',
    title: 'Office Cleaning',
    shortTitle: 'Office Cleaning',
    tagline: 'Clean, hygienic workspaces that impress clients and staff',
    description: 'Create a professional environment that your team and clients will love. Our office cleaning service covers everything from daily desk wipe-downs to deep carpet cleaning, tailored to your business needs.',
    icon: 'business_center',
    features: [
      'Daily desk and surface cleaning',
      'Kitchen and break room maintenance',
      'Washroom cleaning and restocking',
      'Carpet vacuuming and spot cleaning',
      'Rubbish removal and bin replacement',
      'Meeting room preparation',
      'Reception area cleaning',
      'Touchpoint sanitisation'
    ],
    benefits: [
      'Cleaning outside office hours available',
      'DBS-checked, professional staff',
      'Eco-friendly and hypoallergenic products',
      'Public liability insurance up to £10m',
      'Free initial consultation and quote'
    ],
    suitableFor: 'Small offices, coworking spaces, corporate headquarters',
    priceFrom: '£12 per hour',
    slug: 'office-cleaning'
  }
];

export const serviceCategories = [
  { id: 'domestic', title: 'Domestic Cleaning', slug: 'domestic-cleaning' },
  { id: 'commercial', title: 'Commercial Cleaning', slug: 'commercial-cleaning' },
  { id: 'deep', title: 'Deep Cleaning', slug: 'deep-cleaning' },
  { id: 'end-of-tenancy', title: 'End of Tenancy Cleaning', slug: 'end-of-tenancy-cleaning' },
  { id: 'airbnb', title: 'Airbnb Cleaning', slug: 'airbnb-cleaning' },
  { id: 'office', title: 'Office Cleaning', slug: 'office-cleaning' }
];